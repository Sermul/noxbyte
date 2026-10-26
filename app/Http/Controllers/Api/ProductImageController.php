<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $path = $request->file('image')->store('products', 'public');
        if (! is_string($path) || $path === '') {
            return response()->json([
                'success' => false,
                'message' => 'Penyimpanan gambar gagal. Silakan coba kembali.',
            ], 500);
        }

        $image = $product->images()->create([
            'image_path' => $path,
            'alt_text' => $validated['alt_text'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'data' => $image->setAttribute('url', Storage::disk('public')->url($path)),
        ], 201);
    }

    public function destroy(ProductImage $image): JsonResponse
    {
        if (Storage::disk('public')->exists($image->image_path)
            && ! Storage::disk('public')->delete($image->image_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Penghapusan gambar gagal. Silakan coba kembali.',
            ], 500);
        }

        $image->delete();

        return response()->json(['success' => true, 'message' => 'Gambar produk berhasil dihapus.']);
    }
}
