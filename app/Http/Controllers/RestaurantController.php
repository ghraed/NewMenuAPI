<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestaurantController extends Controller
{
    public function updateName(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->loadMissing('restaurant');

        if (! $user->restaurant) {
            return response()->json([
                'message' => 'No restaurant is linked to this account',
            ], 403);
        }

        $user->restaurant->update([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Restaurant name updated successfully.',
            'restaurant' => [
                'id' => $user->restaurant->id,
                'name' => $user->restaurant->name,
                'slug' => $user->restaurant->slug,
            ],
        ]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'email' => $this->normalizeOptionalString($request->input('email')),
            'phone' => $this->normalizeOptionalString($request->input('phone')),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|required_without:phone|unique:users,email',
            'phone' => 'nullable|string|max:40|required_without:email|unique:users,phone',
        ]);

        $admin = $request->user();
        $admin->loadMissing('restaurant');

        if (! $admin->restaurant) {
            return response()->json([
                'message' => 'No restaurant is linked to this account',
            ], 403);
        }

        $temporaryPassword = Str::random(12);

        $staff = DB::transaction(function () use ($admin, $validated, $temporaryPassword) {
            $staff = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'role' => User::ROLE_STAFF,
                'password' => $temporaryPassword,
            ]);

            $admin->restaurant->staffUsers()->syncWithoutDetaching([$staff->id]);

            return $staff;
        });

        return response()->json([
            'message' => 'Staff member created successfully.',
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'role' => $staff->role,
                'created_at' => $staff->created_at?->toIso8601String(),
            ],
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
