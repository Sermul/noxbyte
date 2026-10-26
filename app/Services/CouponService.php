<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public function findValidCoupon(string $code, float $subtotal): Coupon
    {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();

        if (! $coupon
            || ! $coupon->is_active
            || ($coupon->starts_at && $coupon->starts_at->isFuture())
            || ($coupon->expires_at && $coupon->expires_at->isPast())
            || ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit)
            || $subtotal < (float) $coupon->minimum_purchase) {
            throw ValidationException::withMessages([
                'code' => ['Kupon tidak valid atau tidak memenuhi syarat penggunaan.'],
            ]);
        }

        return $coupon;
    }

    public function discount(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->discount_type === 'percentage'
            ? $subtotal * ((float) $coupon->discount_value / 100)
            : (float) $coupon->discount_value;

        if ($coupon->maximum_discount !== null) {
            $discount = min($discount, (float) $coupon->maximum_discount);
        }

        return round(min($subtotal, max(0, $discount)), 2);
    }
}
