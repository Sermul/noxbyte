<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(): JsonResponse
    {
        $reviews = Review::with(['user:id,name', 'product:id,name,slug'])
            ->latest()
            ->paginate(20);

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

    public function update(Request $request, Review $review): JsonResponse
    {
        $validated = $request->validate([
            'is_approved' => ['required', 'boolean'],
        ]);
        $review->update($validated);

        return response()->json(['success' => true, 'data' => $review->refresh()]);
    }

    public function destroy(Review $review): JsonResponse
    {
        $review->delete();

        return response()->json(['success' => true, 'message' => 'Ulasan berhasil dihapus.']);
    }
}
