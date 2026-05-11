<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly ReservationAvailabilityService $availabilityService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(Restaurant $restaurant, array $payload): Reservation
    {
        return DB::transaction(function () use ($restaurant, $payload): Reservation {
            $roomPlan = $this->resolveRoomPlan($restaurant, (int) $payload['room_plan_id']);
            $roomPlanItem = $this->resolveTableItem($roomPlan, (int) $payload['room_plan_item_id'], true);

            $range = $this->availabilityService->buildDateTimeRange(
                (string) $payload['reservation_date'],
                (string) $payload['start_time'],
                (string) $payload['end_time'],
            );

            if ($this->availabilityService->hasBlockingEventOverlapForRestaurant(
                (int) $restaurant->id,
                $range['start_at'],
                $range['end_at'],
            )) {
                throw ValidationException::withMessages([
                    'overlap' => 'Venue booked for private event.',
                ]);
            }

            $status = strtolower(trim((string) ($payload['status'] ?? Reservation::STATUS_RESERVED)));
            if (! in_array($status, Reservation::supportedStatuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Unsupported reservation status.',
                ]);
            }

            if (in_array($status, Reservation::blockingStatuses(), true)) {
                $overlaps = $this->availabilityService->hasBlockingOverlap(
                    $roomPlanItem->id,
                    $range['start_at'],
                    $range['end_at'],
                    null,
                    true,
                );

                if ($overlaps) {
                    throw ValidationException::withMessages([
                        'overlap' => 'Selected table is unavailable for the selected time range.',
                    ]);
                }
            }

            return Reservation::query()->create([
                'restaurant_id' => $restaurant->id,
                'room_plan_id' => $roomPlan->id,
                'room_plan_item_id' => $roomPlanItem->id,
                'customer_name' => trim((string) $payload['customer_name']),
                'customer_phone' => trim((string) $payload['customer_phone']),
                'customer_email' => $this->normalizeOptionalString($payload['customer_email'] ?? null),
                'reservation_date' => (string) $payload['reservation_date'],
                'start_time' => (string) $payload['start_time'],
                'end_time' => (string) $payload['end_time'],
                'start_at' => $range['start_at'],
                'end_at' => $range['end_at'],
                'status' => $status,
                'notes' => $this->normalizeOptionalString($payload['notes'] ?? null),
            ])->fresh(['roomPlan', 'roomPlanItem']);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(Restaurant $restaurant, Reservation $reservation, array $payload): Reservation
    {
        $this->assertReservationBelongsToRestaurant($reservation, $restaurant);

        return DB::transaction(function () use ($restaurant, $reservation, $payload): Reservation {
            $lockedReservation = Reservation::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $roomPlanId = (int) ($payload['room_plan_id'] ?? $lockedReservation->room_plan_id);
            $roomPlanItemId = (int) ($payload['room_plan_item_id'] ?? $lockedReservation->room_plan_item_id);
            $roomPlan = $this->resolveRoomPlan($restaurant, $roomPlanId);
            $roomPlanItem = $this->resolveTableItem($roomPlan, $roomPlanItemId, true);

            $reservationDate = (string) ($payload['reservation_date'] ?? $lockedReservation->reservation_date?->format('Y-m-d'));
            $startTime = (string) ($payload['start_time'] ?? $lockedReservation->start_time);
            $endTime = (string) ($payload['end_time'] ?? $lockedReservation->end_time);
            $status = strtolower(trim((string) ($payload['status'] ?? $lockedReservation->status)));

            if (! in_array($status, Reservation::supportedStatuses(), true)) {
                throw ValidationException::withMessages([
                    'status' => 'Unsupported reservation status.',
                ]);
            }

            $range = $this->availabilityService->buildDateTimeRange($reservationDate, $startTime, $endTime);

            if ($this->availabilityService->hasBlockingEventOverlapForRestaurant(
                (int) $restaurant->id,
                $range['start_at'],
                $range['end_at'],
            )) {
                throw ValidationException::withMessages([
                    'overlap' => 'Venue booked for private event.',
                ]);
            }

            if (in_array($status, Reservation::blockingStatuses(), true)) {
                $overlaps = $this->availabilityService->hasBlockingOverlap(
                    $roomPlanItem->id,
                    $range['start_at'],
                    $range['end_at'],
                    $lockedReservation->id,
                    true,
                );

                if ($overlaps) {
                    throw ValidationException::withMessages([
                        'overlap' => 'Selected table is unavailable for the selected time range.',
                    ]);
                }
            }

            $lockedReservation->update([
                'room_plan_id' => $roomPlan->id,
                'room_plan_item_id' => $roomPlanItem->id,
                'customer_name' => trim((string) ($payload['customer_name'] ?? $lockedReservation->customer_name)),
                'customer_phone' => trim((string) ($payload['customer_phone'] ?? $lockedReservation->customer_phone)),
                'customer_email' => array_key_exists('customer_email', $payload)
                    ? $this->normalizeOptionalString($payload['customer_email'])
                    : $lockedReservation->customer_email,
                'reservation_date' => $reservationDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'start_at' => $range['start_at'],
                'end_at' => $range['end_at'],
                'status' => $status,
                'notes' => array_key_exists('notes', $payload)
                    ? $this->normalizeOptionalString($payload['notes'])
                    : $lockedReservation->notes,
            ]);

            return $lockedReservation->fresh(['roomPlan', 'roomPlanItem']);
        });
    }

    public function updateStatus(Restaurant $restaurant, Reservation $reservation, string $status): Reservation
    {
        return $this->update($restaurant, $reservation, [
            'status' => $status,
        ]);
    }

    private function resolveRoomPlan(Restaurant $restaurant, int $roomPlanId): RoomPlan
    {
        $roomPlan = RoomPlan::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($roomPlanId)
            ->first();

        if (! $roomPlan) {
            throw ValidationException::withMessages([
                'room_plan_id' => 'Room plan does not exist for this restaurant.',
            ]);
        }

        return $roomPlan;
    }

    private function resolveTableItem(RoomPlan $roomPlan, int $itemId, bool $lock): RoomPlanItem
    {
        $query = RoomPlanItem::query()
            ->where('room_plan_id', $roomPlan->id)
            ->whereKey($itemId)
            ->where('is_active', true);

        if ($lock) {
            $query->lockForUpdate();
        }

        $item = $query->first();

        if (! $item) {
            throw ValidationException::withMessages([
                'room_plan_item_id' => 'Selected table does not exist in this room plan.',
            ]);
        }

        if (! $item->isTable()) {
            throw ValidationException::withMessages([
                'room_plan_item_id' => 'Only table items can be reserved.',
            ]);
        }

        return $item;
    }

    private function assertReservationBelongsToRestaurant(Reservation $reservation, Restaurant $restaurant): void
    {
        if ($reservation->restaurant_id !== $restaurant->id) {
            abort(404);
        }
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
