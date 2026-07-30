<?php

namespace Database\Factories;

use App\Models\RestaurantTable;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomPlanItem>
 */
class RoomPlanItemFactory extends Factory
{
    protected $model = RoomPlanItem::class;

    public function definition(): array
    {
        return [
            'room_plan_id' => RoomPlan::factory(),
            'restaurant_table_id' => null,
            'type' => RoomPlanItem::TYPE_TABLE,
            'label' => 'Table '.fake()->unique()->numerify('##'),
            'x' => 100,
            'y' => 100,
            'width' => 120,
            'height' => 120,
            'rotation' => 0,
            'seats' => fake()->numberBetween(2, 8),
            'z_index' => 1,
            'container' => RoomPlanItem::CONTAINER_ROOM,
            'is_active' => true,
        ];
    }

    public function linkedTable(?RestaurantTable $table = null): static
    {
        return $this->state(fn (): array => [
            'restaurant_table_id' => $table?->id ?? RestaurantTable::factory(),
        ]);
    }

    public function nonTable(string $type = RoomPlanItem::TYPE_BAR): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'seats' => null,
        ]);
    }
}
