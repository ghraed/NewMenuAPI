<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $date = now()->toDateString();

        return [
            'restaurant_id' => Restaurant::factory(),
            'room_plan_id' => RoomPlan::factory(),
            'room_plan_item_id' => RoomPlanItem::factory(),
            'customer_name' => fake()->name(),
            'customer_phone' => fake()->phoneNumber(),
            'customer_email' => fake()->safeEmail(),
            'reservation_date' => $date,
            'start_time' => '19:00',
            'end_time' => '20:30',
            'start_at' => now()->setTime(19, 0),
            'end_at' => now()->setTime(20, 30),
            'status' => Reservation::STATUS_RESERVED,
            'notes' => null,
        ];
    }
}

