<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __invoke(Request $request, CouponService $coupons): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['required', 'integer'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['required', 'string', 'in:manual_transfer,cash_on_delivery'],
        ]);
        /** @var User $user */
        $user = $request->user();

        $order = DB::transaction(function () use ($user, $validated, $coupons): Order {
            /** @var Address $address */
            $address = $user->addresses()->findOrFail($validated['address_id']);
            /** @var Cart|null $cart */
            $cart = $user->cart()->with('items')->lockForUpdate()->first();

            if (! $cart || $cart->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => ['Keranjang masih kosong.'],
                ]);
            }

            $subtotalCents = 0;
            $lockedProducts = [];
            foreach ($cart->items as $item) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if (! $product || ! $product->is_active || $product->stock < $item->quantity) {
                    throw ValidationException::withMessages([
                        'cart' => ['Produk tidak aktif atau stok tidak mencukupi.'],
                    ]);
                }

                $subtotalCents += $this->toCents($product->price) * $item->quantity;
                $lockedProducts[] = [$product, $item->quantity];
            }

            $subtotal = $this->fromCents($subtotalCents);
            $shippingCost = max(0, $this->toCents(config('store.shipping_cost', 0)));
            if ($subtotalCents + $shippingCost > 999999999999) {
                throw ValidationException::withMessages([
                    'cart' => ['Total pesanan melebihi batas transaksi yang didukung.'],
                ]);
            }
            $coupon = null;
            $discount = 0.0;
            if (! empty($validated['coupon_code'])) {
                $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower($validated['coupon_code'])])
                    ->lockForUpdate()
                    ->first();
                if (! $coupon) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Kupon tidak valid atau tidak memenuhi syarat penggunaan.'],
                    ]);
                }
                $coupon = $coupons->findValidCoupon($coupon->code, $subtotal);
                $discount = $coupons->discount($coupon, $subtotal);
            }

            $order = $user->orders()->create([
                'order_number' => 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(10)),
                'recipient_name' => $address->recipient_name,
                'phone' => $address->phone,
                'province' => $address->province,
                'city' => $address->city,
                'district' => $address->district,
                'postal_code' => $address->postal_code,
                'address_line' => $address->address_line,
                'subtotal' => $subtotal,
                'shipping_cost' => $this->fromCents($shippingCost),
                'discount_amount' => $discount,
                'total_amount' => $this->fromCents(max(0, $subtotalCents + $shippingCost - $this->toCents($discount))),
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'coupon_id' => $coupon?->id,
                'notes' => $validated['notes'] ?? null,
                'placed_at' => now(),
            ]);

            foreach ($cart->items as $item) {
                $product = $item->product()->first();
                $unitPrice = $this->toCents($product->price);
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $this->fromCents($unitPrice),
                    'quantity' => $item->quantity,
                    'subtotal' => $this->fromCents($unitPrice * $item->quantity),
                ]);
            }

            foreach ($lockedProducts as [$product, $quantity]) {
                $updated = Product::whereKey($product->id)
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($updated !== 1) {
                    throw ValidationException::withMessages([
                        'cart' => ['Stok berubah selama checkout. Silakan coba kembali.'],
                    ]);
                }
            }

            $order->payments()->create([
                'payment_method' => $validated['payment_method'],
                'amount' => $order->total_amount,
                'status' => 'unpaid',
            ]);

            if ($coupon) {
                $coupon->usages()->create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'discount_amount' => $discount,
                ]);
                $coupon->increment('used_count');
            }

            $cart->items()->delete();

            return $order->load('items', 'payments');
        });

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil dibuat.',
            'data' => $order,
        ], 201);
    }

    private function toCents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fromCents(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
