<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $query = StaffShift::query()
            ->where('restaurant_id', $restaurant->id)
            ->with('user:id,name,email,phone,role')
            ->orderBy('shift_date')
            ->orderBy('start_time');

        if (! empty($validated['date_from'])) {
            $query->whereDate('shift_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('shift_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['user_id'])) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        $shifts = $query->get();

        return response()->json([
            'shifts' => $shifts->map(fn (StaffShift $shift): array => $this->formatShift($shift))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'shift_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'position' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $employee = $this->resolveRestaurantEmployee($restaurant, (int) $validated['user_id']);

        $shift = StaffShift::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $employee->id,
            'shift_date' => $validated['shift_date'],
            'start_time' => $validated['start_time'].':00',
            'end_time' => $validated['end_time'].':00',
            'position' => $this->normalizeOptionalString($validated['position'] ?? null),
            'status' => $validated['status'] ?? 'scheduled',
            'notes' => $this->normalizeOptionalString($validated['notes'] ?? null),
        ]);

        $shift->load('user:id,name,email,phone,role');

        return response()->json([
            'message' => 'Shift created successfully.',
            'shift' => $this->formatShift($shift),
        ], 201);
    }

    public function update(Request $request, StaffShift $staffShift): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $shift = $this->assertBelongsToRestaurant($staffShift, $restaurant);

        $validated = $request->validate([
            'user_id' => ['sometimes', 'required', 'integer'],
            'shift_date' => ['sometimes', 'required', 'date'],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => ['sometimes', 'required', 'date_format:H:i'],
            'position' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'required', 'in:scheduled,completed,cancelled'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $payload = [];

        if (array_key_exists('user_id', $validated)) {
            $employee = $this->resolveRestaurantEmployee($restaurant, (int) $validated['user_id']);
            $payload['user_id'] = $employee->id;
        }
        if (array_key_exists('shift_date', $validated)) {
            $payload['shift_date'] = $validated['shift_date'];
        }
        if (array_key_exists('start_time', $validated)) {
            $payload['start_time'] = $validated['start_time'].':00';
        }
        if (array_key_exists('end_time', $validated)) {
            $payload['end_time'] = $validated['end_time'].':00';
        }
        $startTime = $payload['start_time'] ?? $shift->start_time;
        $endTime = $payload['end_time'] ?? $shift->end_time;
        if ($endTime <= $startTime) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after start time.',
            ]);
        }
        if (array_key_exists('position', $validated)) {
            $payload['position'] = $this->normalizeOptionalString($validated['position']);
        }
        if (array_key_exists('status', $validated)) {
            $payload['status'] = $validated['status'];
        }
        if (array_key_exists('notes', $validated)) {
            $payload['notes'] = $this->normalizeOptionalString($validated['notes']);
        }

        if ($payload !== []) {
            $shift->update($payload);
        }

        $shift->load('user:id,name,email,phone,role');

        return response()->json([
            'message' => 'Shift updated successfully.',
            'shift' => $this->formatShift($shift->fresh('user:id,name,email,phone,role')),
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

    private function assertBelongsToRestaurant(StaffShift $shift, Restaurant $restaurant): StaffShift
    {
        if ((int) $shift->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $shift;
    }

    private function resolveRestaurantEmployee(Restaurant $restaurant, int $userId): User
    {
        $employee = $restaurant->staffUsers()
            ->where('users.id', $userId)
            ->whereIn('users.role', [User::ROLE_STAFF, User::ROLE_CHEF])
            ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'user_id' => 'Selected user is not an employee in this restaurant.',
            ]);
        }

        return $employee;
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
    private function formatShift(StaffShift $shift): array
    {
        return [
            'id' => $shift->id,
            'restaurant_id' => $shift->restaurant_id,
            'user_id' => $shift->user_id,
            'shift_date' => $shift->shift_date?->toDateString(),
            'start_time' => $shift->start_time,
            'end_time' => $shift->end_time,
            'position' => $shift->position,
            'status' => $shift->status,
            'notes' => $shift->notes,
            'created_at' => $shift->created_at?->toISOString(),
            'updated_at' => $shift->updated_at?->toISOString(),
            'employee' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
                'email' => $shift->user->email,
                'phone' => $shift->user->phone,
                'role' => $shift->user->role,
            ] : null,
        ];
    }
}
