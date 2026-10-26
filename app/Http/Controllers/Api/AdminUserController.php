<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role', 'created_at'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::query()
            ->select(['id', 'name', 'email', 'phone', 'role', 'created_at'])
            ->withCount('orders')
            ->findOrFail($id);

        return response()->json(['success' => true, 'data' => $user]);
    }
}
