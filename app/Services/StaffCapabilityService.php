<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;

class StaffCapabilityService
{
    public function assignedTableIds(User $user, Restaurant $restaurant): array
    {
        $user->loadMissing(['assignedTables' => function ($query) use ($restaurant) {
            $query->where('restaurant_id', $restaurant->id);
        }]);

        return $user->assignedTables
            ->pluck('id')
            ->map(fn ($tableId) => (int) $tableId)
            ->all();
    }

    public function assertCanAccessOrder(User $user, Restaurant $restaurant, Order $order): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $this->assertTableAssignment($user, $restaurant, $order->restaurant_table_id);
    }

    public function assertCanAccessSession(User $user, Restaurant $restaurant, TableSession $session): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $this->assertTableAssignment($user, $restaurant, $session->restaurant_table_id);
    }

    public function assertCanAccessWave(User $user, Restaurant $restaurant, TableWave $wave): void
    {
        if (! $user->isStaff()) {
            return;
        }

        $this->assertTableAssignment($user, $restaurant, $wave->restaurant_table_id);
    }

    public function filterAssignedTableIdsForStaff(User $user, Restaurant $restaurant): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        return $this->assignedTableIds($user, $restaurant);
    }

    public function assertCanMutateReservations(User $user): void
    {
        if (! $user->isStaff()) {
            return;
        }

        abort(403, 'Staff can only view reservations.');
    }

    private function assertTableAssignment(User $user, Restaurant $restaurant, ?int $restaurantTableId): void
    {
        $assignedTableIds = $this->assignedTableIds($user, $restaurant);

        if ($restaurantTableId === null || ! in_array($restaurantTableId, $assignedTableIds, true)) {
            abort(403, 'This staff account is not assigned to that table.');
        }
    }
}
