<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceVendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $vendors = Vendor::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByRaw('is_active desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'vendors' => $vendors->map(fn (Vendor $vendor): array => $this->formatVendor($vendor))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:160',
                Rule::unique('vendors', 'name')->where('restaurant_id', $restaurant->id),
            ],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $vendor = Vendor::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => trim((string) $validated['name']),
            'contact_name' => $this->normalizeOptionalString($validated['contact_name'] ?? null),
            'phone' => $this->normalizeOptionalString($validated['phone'] ?? null),
            'email' => $this->normalizeOptionalString($validated['email'] ?? null),
            'tax_number' => $this->normalizeOptionalString($validated['tax_number'] ?? null),
            'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Vendor created successfully.',
            'vendor' => $this->formatVendor($vendor),
        ], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $vendor = $this->assertBelongsToRestaurant($vendor, $restaurant);

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:160',
                Rule::unique('vendors', 'name')
                    ->where('restaurant_id', $restaurant->id)
                    ->ignore($vendor->id),
            ],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $payload = [];

        if (array_key_exists('name', $validated)) {
            $payload['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('contact_name', $validated)) {
            $payload['contact_name'] = $this->normalizeOptionalString($validated['contact_name']);
        }
        if (array_key_exists('phone', $validated)) {
            $payload['phone'] = $this->normalizeOptionalString($validated['phone']);
        }
        if (array_key_exists('email', $validated)) {
            $payload['email'] = $this->normalizeOptionalString($validated['email']);
        }
        if (array_key_exists('tax_number', $validated)) {
            $payload['tax_number'] = $this->normalizeOptionalString($validated['tax_number']);
        }
        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $this->normalizeOptionalString($validated['notes']);
        }
        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $vendor->update($payload);
        }

        return response()->json([
            'message' => 'Vendor updated successfully.',
            'vendor' => $this->formatVendor($vendor->fresh()),
        ]);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();
        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    private function assertBelongsToRestaurant(Vendor $vendor, Restaurant $restaurant): Vendor
    {
        if ((int) $vendor->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $vendor;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function formatVendor(Vendor $vendor): array
    {
        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'contact_name' => $vendor->contact_name,
            'phone' => $vendor->phone,
            'email' => $vendor->email,
            'tax_number' => $vendor->tax_number,
            'notes' => $vendor->notes,
            'is_active' => (bool) $vendor->is_active,
            'created_at' => $vendor->created_at?->toISOString(),
            'updated_at' => $vendor->updated_at?->toISOString(),
        ];
    }
}

