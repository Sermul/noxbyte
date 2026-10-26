<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->addresses()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $address = DB::transaction(function () use ($request, $validated): Address {
            if ($validated['is_default'] ?? false) {
                $request->user()->addresses()->update(['is_default' => false]);
            }

            return $request->user()->addresses()->create($validated);
        });

        return response()->json(['success' => true, 'data' => $address], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->addressForUser($request, $id),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $address = $this->addressForUser($request, $id);
        $validated = $request->validate($this->rules(true));

        DB::transaction(function () use ($request, $address, $validated): void {
            if ($validated['is_default'] ?? false) {
                $request->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }

            $address->update($validated);
        });

        return response()->json([
            'success' => true,
            'data' => $address->refresh(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->addressForUser($request, $id)->delete();

        return response()->json(['success' => true, 'message' => 'Alamat berhasil dihapus.']);
    }

    private function addressForUser(Request $request, int $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }

    private function rules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes|required' : 'required';

        return [
            'recipient_name' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:30'],
            'province' => [$required, 'string', 'max:255'],
            'city' => [$required, 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address_line' => [$required, 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
