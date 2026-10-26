<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\CouponUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->withCount('items')
            ->latest()
            ->paginate(12);

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

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = $request->user()->orders()
            ->with(['items', 'payments'])
            ->where('order_number', $orderNumber)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $order]);
    }

    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = DB::transaction(function () use ($request, $orderNumber): Order {
            $order = $request->user()->orders()
                ->where('order_number', $orderNumber)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== 'pending' || $order->payment_status !== 'unpaid') {
                throw ValidationException::withMessages([
                    'order' => ['Pesanan ini tidak dapat dibatalkan.'],
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

            $order->update(['status' => 'cancelled']);

            return $order->load('items', 'payments');
        });

        return response()->json([
            'success' => true,
            'message' => 'Pesanan dibatalkan dan stok dikembalikan.',
            'data' => $order,
        ]);
    }
}
