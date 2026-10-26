<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\CouponUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('user:id,name,email')
            ->withCount('items')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(string $orderNumber): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Order::with(['user:id,name,email', 'items', 'payments'])
                ->where('order_number', $orderNumber)
                ->firstOrFail(),
        ]);
    }

    public function updateStatus(Request $request, string $orderNumber): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:processing,shipped,completed,cancelled'],
        ]);

        $order = DB::transaction(function () use ($validated, $orderNumber): Order {
            $order = Order::where('order_number', $orderNumber)->lockForUpdate()->firstOrFail();
            $allowed = match ($order->status) {
                'pending' => ['processing', 'cancelled'],
                'processing' => ['shipped', 'cancelled'],
                'shipped' => ['completed'],
                default => [],
            };

            if (! in_array($validated['status'], $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Perubahan status pesanan tidak diizinkan.'],
                ]);
            }

            if ($validated['status'] === 'processing'
                && $order->payment_status !== 'paid'
                && ! $order->payments()->where('payment_method', 'cash_on_delivery')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Pembayaran harus diverifikasi sebelum pesanan diproses.'],
                ]);
            }

            if ($validated['status'] === 'cancelled') {
                if ($order->payment_status !== 'unpaid') {
                    throw ValidationException::withMessages([
                        'status' => ['Pesanan yang sudah dibayar tidak dapat dibatalkan melalui alur ini.'],
                    ]);
                }

                foreach ($order->items()->with('product')->get() as $item) {
                    if ($item->product) {
                        $item->product()->increment('stock', $item->quantity);
                    }
                }

                $usage = CouponUsage::where('order_id', $order->id)->first();
                if ($usage) {
                    $usage->coupon()->where('used_count', '>', 0)->decrement('used_count');
                    $usage->delete();
                }
            }

            $order->update(['status' => $validated['status']]);

            return $order->load('items', 'payments');
        });

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function updatePayment(Request $request, string $orderNumber): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:paid,failed,refunded'],
            'transaction_reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('payments', 'transaction_reference'),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order = DB::transaction(function () use ($validated, $orderNumber): Order {
            $order = Order::where('order_number', $orderNumber)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()->latest()->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => ['Pesanan yang dibatalkan tidak dapat menerima perubahan pembayaran.'],
                ]);
            }

            if ($validated['status'] === 'paid' && ! in_array($payment->status, ['unpaid', 'failed'], true)) {
                throw ValidationException::withMessages(['status' => ['Pembayaran tidak dapat ditandai lunas.']]);
            }

            if ($validated['status'] === 'failed' && $payment->status === 'paid') {
                throw ValidationException::withMessages(['status' => ['Pembayaran yang sudah lunas tidak dapat ditandai gagal.']]);
            }

            if ($validated['status'] === 'refunded' && $payment->status !== 'paid') {
                throw ValidationException::withMessages(['status' => ['Hanya pembayaran lunas yang dapat dikembalikan.']]);
            }

            $payment->update([
                'status' => $validated['status'],
                'paid_at' => $validated['status'] === 'paid' ? now() : $payment->paid_at,
                'transaction_reference' => $validated['transaction_reference'] ?? $payment->transaction_reference,
                'notes' => $validated['notes'] ?? $payment->notes,
            ]);
            $order->update(['payment_status' => $validated['status']]);

            return $order->load('items', 'payments');
        });

        return response()->json(['success' => true, 'data' => $order]);
    }
}
