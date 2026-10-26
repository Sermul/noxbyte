<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'users_count' => User::count(),
                'products_count' => Product::count(),
                'active_products_count' => Product::where('is_active', true)->count(),
                'orders_count' => Order::count(),
                'pending_orders_count' => Order::where('status', 'pending')->count(),
                'revenue' => Order::where('payment_status', 'paid')->sum('total_amount'),
            ],
        ]);
    }
}
