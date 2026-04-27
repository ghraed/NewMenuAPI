<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReservationAvailabilityService
{
    /**
     * @return array{start_at: CarbonImmutable, end_at: CarbonImmutable}
     */
    public function buildDateTimeRange(string $reservationDate, string $startTime, string $endTime): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $startAt = CarbonImmutable::parse(trim($reservationDate.' '.$startTime), $timezone);
        $endAt = CarbonImmutable::parse(trim($reservationDate.' '.$endTime), $timezone);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $endAt->addDay();
        }

        return [
            'start_at' => $startAt,
            'end_at' => $endAt,
        ];
    }

    public function hasBlockingOverlap(
        int $roomPlanItemId,
        CarbonImmutable $startAt,
        CarbonImmutable $endAt,
        ?int $ignoreReservationId = null,
        bool $lock = false,
    ): bool {
        $query = Reservation::query()
            ->where('room_plan_item_id', $roomPlanItemId)
            ->whereIn('status', Reservation::blockingStatuses())
            ->where(function (Builder $builder) use ($startAt, $endAt): void {
                // Overlap rule: existing.start_at < selected_end_at && existing.end_at > selected_start_at
                $builder->where('start_at', '<', $endAt)
                    ->where('end_at', '>', $startAt);
            });

        if ($ignoreReservationId) {
            $query->where('id', '!=', $ignoreReservationId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availabilityForPlan(RoomPlan $roomPlan, string $reservationDate, string $startTime, string $endTime): array
    {
        $range = $this->buildDateTimeRange($reservationDate, $startTime, $endTime);
        $startAt = $range['start_at'];
        $endAt = $range['end_at'];

        $tableItems = $roomPlan->items()
            ->where('type', RoomPlanItem::TYPE_TABLE)
            ->where('is_active', true)
            ->get();

        if ($tableItems->isEmpty()) {
            return [];
        }

        $reservations = Reservation::query()
            ->where('room_plan_id', $roomPlan->id)
            ->whereIn('room_plan_item_id', $tableItems->pluck('id')->all())
            ->whereIn('status', [
                Reservation::STATUS_RESERVED,
                Reservation::STATUS_BUSY,
                Reservation::STATUS_NO_SHOW,
            ])
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->get()
            ->groupBy('room_plan_item_id');

        return $tableItems->map(function (RoomPlanItem $item) use ($reservations): array {
            $itemReservations = $reservations->get($item->id, collect());
            $status = $this->resolveVisualStatus($itemReservations);

            return [
                'room_plan_item_id' => $item->id,
                'restaurant_table_id' => $item->restaurant_table_id,
                'label' => $item->label,
                'status' => $status,
                'color' => $this->statusColor($status),
                'is_selectable' => ! in_array($status, [Reservation::STATUS_RESERVED, Reservation::STATUS_BUSY], true),
            ];
        })->values()->all();
    }

    private function resolveVisualStatus(Collection $reservations): string
    {
        $statuses = $reservations->pluck('status')->all();

        if (in_array(Reservation::STATUS_BUSY, $statuses, true)) {
            return Reservation::STATUS_BUSY;
        }

        if (in_array(Reservation::STATUS_RESERVED, $statuses, true)) {
            return Reservation::STATUS_RESERVED;
        }

        if (in_array(Reservation::STATUS_NO_SHOW, $statuses, true)) {
            return Reservation::STATUS_NO_SHOW;
        }

        return 'free';
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            Reservation::STATUS_BUSY => 'red',
            Reservation::STATUS_RESERVED => 'orange',
            Reservation::STATUS_NO_SHOW => 'gray',
            default => 'green',
        };
    }
}
