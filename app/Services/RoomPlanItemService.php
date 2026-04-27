<?php

namespace App\Services;

use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomPlanItemService
{
    public function __construct(
        private readonly RoomPlanTableSyncService $tableSyncService,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function saveBulk(RoomPlan $roomPlan, array $items): Collection
    {
        return DB::transaction(function () use ($roomPlan, $items): Collection {
            $existing = $roomPlan->items()->get()->keyBy('id');
            $persistedIds = [];

            foreach ($items as $itemPayload) {
                $incomingId = isset($itemPayload['id']) ? (int) $itemPayload['id'] : null;

                if ($incomingId && $existing->has($incomingId)) {
                    $item = $existing->get($incomingId);
                    $this->updateItem($roomPlan, $item, $itemPayload);
                    $persistedIds[] = $item->id;
                    continue;
                }

                $item = $this->createItem($roomPlan, $itemPayload);
                $persistedIds[] = $item->id;
            }

            $roomPlan->items()
                ->whereNotIn('id', $persistedIds)
                ->get()
                ->each(fn (RoomPlanItem $item) => $this->softDeleteItem($roomPlan, $item));

            return $roomPlan->items()->get();
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createItem(RoomPlan $roomPlan, array $payload): RoomPlanItem
    {
        $normalized = $this->normalizePayload($roomPlan, $payload, null);

        $item = RoomPlanItem::query()->create([
            ...$normalized,
            'room_plan_id' => $roomPlan->id,
            'restaurant_table_id' => isset($payload['restaurant_table_id']) ? (int) $payload['restaurant_table_id'] : null,
        ]);

        $this->tableSyncService->syncFromItem($item);

        return $item->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateItem(RoomPlan $roomPlan, RoomPlanItem $item, array $payload): RoomPlanItem
    {
        $this->assertItemBelongsToPlan($roomPlan, $item);

        $normalized = $this->normalizePayload($roomPlan, $payload, $item);
        $item->update($normalized);

        if ($item->type === RoomPlanItem::TYPE_TABLE) {
            $this->tableSyncService->syncFromItem($item->fresh());
        }

        if (! $item->is_active) {
            $this->tableSyncService->deactivateForItem($item);
        }

        return $item->fresh();
    }

    public function duplicateItem(RoomPlan $roomPlan, RoomPlanItem $item): RoomPlanItem
    {
        $this->assertItemBelongsToPlan($roomPlan, $item);

        $duplicatePayload = [
            'type' => $item->type,
            'label' => trim($item->label).' Copy',
            'x' => min($roomPlan->width - $item->width, $item->x + 24),
            'y' => min($roomPlan->height - $item->height, $item->y + 24),
            'width' => $item->width,
            'height' => $item->height,
            'rotation' => $item->rotation,
            'seats' => $item->seats,
            'z_index' => $item->z_index + 1,
            'container' => $item->container,
            'is_active' => true,
        ];

        return $this->createItem($roomPlan, $duplicatePayload);
    }

    public function softDeleteItem(RoomPlan $roomPlan, RoomPlanItem $item): void
    {
        $this->assertItemBelongsToPlan($roomPlan, $item);

        DB::transaction(function () use ($item): void {
            $item->update(['is_active' => false]);
            $this->tableSyncService->deactivateForItem($item);
            $item->delete();
        });
    }

    private function assertItemBelongsToPlan(RoomPlan $roomPlan, RoomPlanItem $item): void
    {
        if ($item->room_plan_id !== $roomPlan->id) {
            abort(404);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(RoomPlan $roomPlan, array $payload, ?RoomPlanItem $existing): array
    {
        $type = strtolower(trim((string) ($payload['type'] ?? $existing?->type ?? '')));
        $label = trim((string) ($payload['label'] ?? $existing?->label ?? ''));
        $x = isset($payload['x']) ? (float) $payload['x'] : (float) ($existing?->x ?? 0);
        $y = isset($payload['y']) ? (float) $payload['y'] : (float) ($existing?->y ?? 0);
        $width = isset($payload['width']) ? (float) $payload['width'] : (float) ($existing?->width ?? 0);
        $height = isset($payload['height']) ? (float) $payload['height'] : (float) ($existing?->height ?? 0);
        $rotation = isset($payload['rotation']) ? (float) $payload['rotation'] : (float) ($existing?->rotation ?? 0);
        $zIndex = isset($payload['z_index']) ? (int) $payload['z_index'] : (int) ($existing?->z_index ?? 0);
        $container = strtolower(trim((string) ($payload['container'] ?? $existing?->container ?? RoomPlanItem::CONTAINER_WRAPPER)));
        $isActive = isset($payload['is_active']) ? (bool) $payload['is_active'] : (bool) ($existing?->is_active ?? true);
        $seats = array_key_exists('seats', $payload)
            ? $this->normalizeNullableInt($payload['seats'])
            : $existing?->seats;

        $this->validateCoordinates($roomPlan, $x, $y, $width, $height);

        if (! in_array($type, RoomPlanItem::supportedTypes(), true)) {
            throw ValidationException::withMessages([
                'type' => 'Unsupported room plan item type.',
            ]);
        }

        if (! in_array($container, RoomPlanItem::supportedContainers(), true)) {
            throw ValidationException::withMessages([
                'container' => 'Container must be room or wrapper.',
            ]);
        }

        if ($label === '') {
            throw ValidationException::withMessages([
                'label' => 'Item label is required.',
            ]);
        }

        if ($type === RoomPlanItem::TYPE_TABLE) {
            if ($seats === null || $seats < 1) {
                throw ValidationException::withMessages([
                    'seats' => 'Seats are required for table items.',
                ]);
            }
        } else {
            $seats = null;
        }

        return [
            'type' => $type,
            'label' => mb_substr($label, 0, 120),
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'rotation' => $rotation,
            'seats' => $seats,
            'z_index' => $zIndex,
            'container' => $container,
            'is_active' => $isActive,
        ];
    }

    private function validateCoordinates(RoomPlan $roomPlan, float $x, float $y, float $width, float $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw ValidationException::withMessages([
                'width' => 'Width and height must be greater than zero.',
            ]);
        }

        if ($x < 0 || $y < 0) {
            throw ValidationException::withMessages([
                'x' => 'Coordinates must be within room plan bounds.',
            ]);
        }

        if (($x + $width) > $roomPlan->width || ($y + $height) > $roomPlan->height) {
            throw ValidationException::withMessages([
                'bounds' => 'Item must stay inside room plan boundaries.',
            ]);
        }
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
