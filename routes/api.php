<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminCouponController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminReviewController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\WishlistController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/categories', [CategoryController::class, 'index'])->name('legacy.categories.index');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('legacy.categories.show');

Route::prefix('v1')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{product}/reviews', [ReviewController::class, 'index']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'updatePassword']);
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::apiResource('addresses', AddressController::class)->except(['create', 'edit']);

        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'addItem']);
        Route::put('/cart/items/{id}', [CartController::class, 'updateItem']);
        Route::delete('/cart/items/{id}', [CartController::class, 'removeItem']);
        Route::delete('/cart', [CartController::class, 'clear']);

        Route::post('/checkout', CheckoutController::class);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderNumber}', [OrderController::class, 'show']);
        Route::post('/orders/{orderNumber}/cancel', [OrderController::class, 'cancel']);

        Route::get('/wishlist', [WishlistController::class, 'index']);
        Route::post('/wishlist/{productId}', [WishlistController::class, 'store']);
        Route::delete('/wishlist/{productId}', [WishlistController::class, 'destroy']);

        Route::post('/products/{productId}/reviews', [ReviewController::class, 'store']);
        Route::post('/coupons/validate', [CouponController::class, 'validateCoupon']);
    });

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'admin'])
        ->group(function (): void {
            Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
            Route::apiResource('products', ProductController::class)->except(['index', 'show']);
            Route::post('/products/{product}/images', [ProductImageController::class, 'store']);
            Route::delete('/product-images/{image}', [ProductImageController::class, 'destroy']);

            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{id}', [AdminUserController::class, 'show']);
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::get('/orders/{orderNumber}', [AdminOrderController::class, 'show']);
            Route::patch('/orders/{orderNumber}/status', [AdminOrderController::class, 'updateStatus']);
            Route::patch('/orders/{orderNumber}/payment', [AdminOrderController::class, 'updatePayment']);

            Route::apiResource('coupons', AdminCouponController::class)->except(['create', 'edit']);
            Route::get('/reviews', [AdminReviewController::class, 'index']);
            Route::patch('/reviews/{review}', [AdminReviewController::class, 'update']);
            Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy']);
            Route::get('/dashboard', AdminDashboardController::class);
        });
});