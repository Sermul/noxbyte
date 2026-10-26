<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar kategori berhasil diambil.',
            'data' => $categories,
        ]);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $category->loadCount([
                'products' => fn ($query) => $query->where('is_active', true),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
        ]);

        if (! isset($validated['slug'])) {
            $baseSlug = Str::slug($validated['name']) ?: 'category';
            $validated['slug'] = $baseSlug;
            $suffix = 2;

            while (Category::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] = $baseSlug.'-'.$suffix++;
            }
        }

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dibuat.',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil diperbarui.',
            'data' => $category->refresh(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kategori yang masih memiliki produk tidak dapat dihapus.',
            ], 409);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori berhasil dihapus.',
        ]);
    }
}
