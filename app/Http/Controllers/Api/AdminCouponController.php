<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCouponController extends Controller
{
    public function index(): JsonResponse
    {
        $coupons = Coupon::latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $coupons->items(),
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code', '')))]);
        $validated = $request->validate($this->rules());
        $coupon = Coupon::create($validated);

        return response()->json(['success' => true, 'data' => $coupon], 201);
    }

    public function show(Coupon $coupon): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $coupon]);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        if ($request->has('code')) {
            $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);
        }
        $validated = $request->validate($this->rules($coupon));

        $coupon->update($validated);

        return response()->json(['success' => true, 'data' => $coupon->refresh()]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->usages()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Kupon yang sudah digunakan tidak dapat dihapus.',
            ], 409);
        }

        $coupon->delete();

        return response()->json(['success' => true, 'message' => 'Kupon berhasil dihapus.']);
    }

    private function rules(?Coupon $coupon = null): array
    {
        $codeUnique = Rule::unique('coupons', 'code');
        if ($coupon) {
            $codeUnique->ignore($coupon->id);
        }

        return [
            'code' => [$coupon ? 'sometimes' : 'required', 'string', 'max:100', $codeUnique],
            'description' => ['sometimes', 'nullable', 'string'],
            'discount_type' => [$coupon ? 'sometimes' : 'required', 'in:percentage,fixed'],
            'discount_value' => [
                $coupon ? 'sometimes' : 'required',
                'numeric',
                'gt:0',
                'max:9999999999.99',
                Rule::when(
                    request()->input('discount_type', $coupon?->discount_type) === 'percentage',
                    ['max:100']
                ),
            ],
            'minimum_purchase' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'maximum_discount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
