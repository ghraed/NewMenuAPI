<?php

namespace App\Services;

use App\Models\RestaurantTable;
use App\Models\RoomPlanItem;

class RoomPlanTableSyncService
{
    public function __construct(
        private readonly TableManagementModeService $tableManagementModeService,
    ) {
    }

    public function syncFromItem(RoomPlanItem $item): ?RestaurantTable
    {
        $item->loadMissing('roomPlan');

        if ($item->type !== RoomPlanItem::TYPE_TABLE || ! $item->roomPlan) {
            return null;
        }

        if ($this->tableManagementModeService->resolveMode($item->roomPlan->restaurant) !== TableManagementModeService::MODE_ROOM_PLAN) {
            return null;
        }

        $restaurantId = (int) $item->roomPlan->restaurant_id;
        $targetName = $this->resolveUniqueName(
            $restaurantId,
            $this->normalizeTableName($item->label),
            $item->restaurant_table_id
        );

        $table = null;

        if ($item->restaurant_table_id) {
            $table = RestaurantTable::query()
                ->where('restaurant_id', $restaurantId)
                ->whereKey($item->restaurant_table_id)
                ->first();
        }

        if (! $table) {
            $table = RestaurantTable::query()->create([
                'restaurant_id' => $restaurantId,
                'name' => $targetName,
                'is_active' => (bool) $item->is_active,
                'seats' => $item->seats,
            ]);

            $item->update([
                'restaurant_table_id' => $table->id,
            ]);

            return $table;
        }

        $table->update([
            'name' => $targetName,
            'is_active' => (bool) $item->is_active,
            'seats' => $item->seats,
        ]);

        return $table;
    }

    public function deactivateForItem(RoomPlanItem $item): void
    {
        if ($item->type !== RoomPlanItem::TYPE_TABLE || ! $item->restaurant_table_id) {
            return;
        }

        $item->loadMissing('roomPlan');
        if (! $item->roomPlan || $this->tableManagementModeService->resolveMode($item->roomPlan->restaurant) !== TableManagementModeService::MODE_ROOM_PLAN) {
            return;
        }

        $hasOtherActiveLinks = RoomPlanItem::query()
            ->where('restaurant_table_id', $item->restaurant_table_id)
            ->where('type', RoomPlanItem::TYPE_TABLE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $item->id)
            ->exists();

        if ($hasOtherActiveLinks) {
            return;
        }

        RestaurantTable::query()
            ->whereKey($item->restaurant_table_id)
            ->update(['is_active' => false]);
    }

    private function normalizeTableName(string $label): string
    {
        $normalized = trim($label);

        if ($normalized === '') {
            return 'Table';
        }

        return mb_substr($normalized, 0, 40);
    }

    private function resolveUniqueName(int $restaurantId, string $baseName, ?int $ignoreTableId = null): string
    {
        $candidate = $baseName;
        $suffix = 2;

        while ($this->nameExists($restaurantId, $candidate, $ignoreTableId)) {
            $suffixText = ' '.$suffix;
            $maxBaseLength = max(1, 40 - mb_strlen($suffixText));
            $candidate = mb_substr($baseName, 0, $maxBaseLength).$suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function nameExists(int $restaurantId, string $name, ?int $ignoreTableId = null): bool
    {
        $query = RestaurantTable::query()
            ->where('restaurant_id', $restaurantId)
            ->where('name', $name);

        if ($ignoreTableId) {
            $query->where('id', '!=', $ignoreTableId);
        }

        return $query->exists();
    }
}
