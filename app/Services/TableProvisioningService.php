<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\RoomPlanItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TableProvisioningService
{
    public function __construct(
        private readonly RoomPlanTableSyncService $roomPlanTableSyncService,
    ) {
    }

    public function provisionFromRoomPlan(Restaurant $restaurant): void
    {
        DB::transaction(function () use ($restaurant): void {
            $activeItemTableIds = RoomPlanItem::query()
                ->whereHas('roomPlan', fn ($query) => $query->where('restaurant_id', $restaurant->id))
                ->where('type', RoomPlanItem::TYPE_TABLE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get()
                ->map(function (RoomPlanItem $item): ?int {
                    $table = $this->roomPlanTableSyncService->syncFromItem($item);
                    return $table?->id;
                })
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $query = RestaurantTable::query()
                ->where('restaurant_id', $restaurant->id);

            if ($activeItemTableIds !== []) {
                $query->whereNotIn('id', $activeItemTableIds);
            }

            $query->update(['is_active' => false]);
        });
    }

    public function provisionManualCount(Restaurant $restaurant, int $count): void
    {
        DB::transaction(function () use ($restaurant, $count): void {
            $targetNames = [];

            foreach (range(1, $count) as $number) {
                $name = sprintf('T%02d', $number);
                $targetNames[] = $name;

                RestaurantTable::query()->updateOrCreate(
                    [
                        'restaurant_id' => $restaurant->id,
                        'name' => $name,
                    ],
                    [
                        'is_active' => true,
                        'seats' => null,
                    ]
                );
            }

            RestaurantTable::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereNotIn('name', $targetNames)
                ->update(['is_active' => false]);

            $restaurant->update(['manual_table_count' => $count]);
        });
    }

    public function resetAssignments(Restaurant $restaurant): void
    {
        DB::table('restaurant_table_user')
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_table_user.restaurant_table_id')
            ->where('restaurant_tables.restaurant_id', $restaurant->id)
            ->delete();
    }

    public function removeChefAssignments(Restaurant $restaurant): void
    {
        DB::table('restaurant_table_user')
            ->join('restaurant_tables', 'restaurant_tables.id', '=', 'restaurant_table_user.restaurant_table_id')
            ->join('users', 'users.id', '=', 'restaurant_table_user.user_id')
            ->where('restaurant_tables.restaurant_id', $restaurant->id)
            ->where('users.role', User::ROLE_CHEF)
            ->delete();
    }

    public function deactivateAllTables(Restaurant $restaurant): void
    {
        RestaurantTable::query()
            ->where('restaurant_id', $restaurant->id)
            ->update(['is_active' => false]);
    }
}

