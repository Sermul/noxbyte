<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = $request->user()->wishlistedProducts()
            ->where('is_active', true)
            ->with('category', 'images')
            ->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function store(Request $request, int $productId): JsonResponse
    {
        $product = Product::whereKey($productId)->where('is_active', true)->firstOrFail();
        $request->user()->wishlistedProducts()->syncWithoutDetaching([$product->id]);

        return response()->json(['success' => true, 'message' => 'Produk ditambahkan ke wishlist.'], 201);
    }

    public function destroy(Request $request, int $productId): JsonResponse
    {
        $request->user()->wishlistedProducts()->detach($productId);

        return response()->json(['success' => true, 'message' => 'Produk dihapus dari wishlist.']);
    }
}
