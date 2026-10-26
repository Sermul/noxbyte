<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        abort_unless($product->is_active, 404);

        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user:id,name')
            ->latest()
            ->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $reviews->items(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    public function store(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);
        $product = Product::whereKey($productId)->where('is_active', true)->firstOrFail();
        $order = $request->user()->orders()
            ->whereKey($validated['order_id'])
            ->where('status', 'completed')
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order_id' => ['Ulasan hanya dapat diberikan untuk produk dari pesanan selesai milik Anda.'],
            ]);
        }

        $review = $request->user()->reviews()->create([
            'product_id' => $product->id,
            'order_id' => $order->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Ulasan dikirim dan menunggu moderasi.',
            'data' => $review,
        ], 201);
    }
}
