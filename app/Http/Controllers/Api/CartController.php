<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request)->load('items.product');
        $items = $cart->items->map(fn (CartItem $item): array => [
            'id' => $item->id,
            'product' => $item->product,
            'quantity' => $item->quantity,
            'subtotal' => number_format((float) $item->product->price * $item->quantity, 2, '.', ''),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $cart->id,
                'items' => $items,
                'subtotal' => number_format((float) $items->sum(fn (array $item): float => (float) $item['subtotal']), 2, '.', ''),
            ],
        ]);
    }

    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $product = Product::findOrFail($validated['product_id']);

        if (! $product->is_active) {
            return response()->json(['success' => false, 'message' => 'Produk tidak tersedia.'], 409);
        }

        $cart = $this->cartFor($request);
        $item = $cart->items()->firstOrNew(['product_id' => $product->id]);
        $newQuantity = $item->exists ? $item->quantity + $validated['quantity'] : $validated['quantity'];

        if ($newQuantity > $product->stock) {
            return response()->json(['success' => false, 'message' => 'Stok produk tidak mencukupi.'], 409);
        }

        $item->quantity = $newQuantity;
        $item->save();

        return response()->json([
            'success' => true,
            'message' => 'Keranjang berhasil diperbarui.',
            'data' => $item->load('product'),
        ], 201);
    }

    public function updateItem(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);
        $item = $this->itemForUser($request, $id)->load('product');

        if (! $item->product->is_active || $validated['quantity'] > $item->product->stock) {
            return response()->json(['success' => false, 'message' => 'Produk tidak tersedia atau stok tidak mencukupi.'], 409);
        }

        $item->update(['quantity' => $validated['quantity']]);

        return response()->json(['success' => true, 'data' => $item->refresh()->load('product')]);
    }

    public function removeItem(Request $request, int $id): JsonResponse
    {
        $this->itemForUser($request, $id)->delete();

        return response()->json(['success' => true, 'message' => 'Produk dihapus dari keranjang.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->cartFor($request)->items()->delete();

        return response()->json(['success' => true, 'message' => 'Keranjang berhasil dikosongkan.']);
    }

    private function cartFor(Request $request): Cart
    {
        return $request->user()->cart()->firstOrCreate([]);
    }

    private function itemForUser(Request $request, int $id): CartItem
    {
        return $request->user()->cart()->firstOrCreate([])->items()->findOrFail($id);
    }
}
