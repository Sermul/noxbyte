<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function validateCoupon(Request $request, CouponService $coupons): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $coupon = $coupons->findValidCoupon($validated['code'], (float) $validated['subtotal']);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $coupon->code,
                'discount_amount' => number_format(
                    $coupons->discount($coupon, (float) $validated['subtotal']),
                    2,
                    '.',
                    ''
                ),
            ],
        ]);
    }
}
