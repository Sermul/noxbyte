<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc', 'rating'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $query = Product::query()
            ->where('is_active', true)
            ->with(['category', 'images'])
            ->withAvg(['reviews as rating_avg' => fn ($reviews) => $reviews->where('is_approved', true)], 'rating')
            ->withCount(['reviews' => fn ($reviews) => $reviews->where('is_approved', true)]);

        if (! empty($filters['search'])) {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        if (! empty($filters['category'])) {
            $query->whereHas('category', fn ($category) => $category->where('slug', $filters['category']));
        }

        if (! empty($filters['brand'])) {
            $query->where('brand', $filters['brand']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (($filters['in_stock'] ?? false) === true || ($filters['in_stock'] ?? null) === 1 || ($filters['in_stock'] ?? null) === '1') {
            $query->where('stock', '>', 0);
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'rating' => $query->orderByDesc('rating_avg'),
            default => $query->latest(),
        };

        $products = $query->paginate($filters['per_page'] ?? 12)->withQueryString();

        return response()->json([
            'success' => true,
            'message' => 'Daftar produk berhasil diambil.',
            'data' => $products->items(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->with(['category', 'images'])
            ->withAvg(['reviews as rating_avg' => fn ($reviews) => $reviews->where('is_approved', true)], 'rating')
            ->withCount(['reviews' => fn ($reviews) => $reviews->where('is_approved', true)])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'brand' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! isset($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']) ?: 'product';
            $validated['slug'] = $baseSlug;
            $suffix = 2;

            while (Product::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = $baseSlug.'-'.$suffix++;
            }
        }

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            if (! is_string($path) || $path === '') {
                return $this->storageFailure();
            }
            $validated['image'] = $path;
        }

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dibuat.',
            'data' => $product->load('category', 'images'),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product->id)],
            'description' => ['sometimes', 'nullable', 'string'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            if (! is_string($path) || $path === '') {
                return $this->storageFailure();
            }
            if ($product->image
                && Storage::disk('public')->exists($product->image)
                && ! Storage::disk('public')->delete($product->image)) {
                return $this->storageFailure();
            }
            $validated['image'] = $path;
        } elseif (array_key_exists('image', $validated) && $validated['image'] === null) {
            if ($product->image
                && Storage::disk('public')->exists($product->image)
                && ! Storage::disk('public')->delete($product->image)) {
                return $this->storageFailure();
            }
        }

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil diperbarui.',
            'data' => $product->refresh()->load('category', 'images'),
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->orderItems()->exists() || $product->cartItems()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Produk yang masih berada di keranjang atau sudah pernah dipesan tidak dapat dihapus.',
            ], 409);
        }

        foreach ($product->images as $image) {
            if (Storage::disk('public')->exists($image->image_path)
                && ! Storage::disk('public')->delete($image->image_path)) {
                return $this->storageFailure();
            }
        }

        if ($product->image
            && Storage::disk('public')->exists($product->image)
            && ! Storage::disk('public')->delete($product->image)) {
            return $this->storageFailure();
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Produk berhasil dihapus.',
        ]);
    }

    private function storageFailure(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Penyimpanan gambar gagal. Silakan coba kembali.',
        ], 500);
    }
}
