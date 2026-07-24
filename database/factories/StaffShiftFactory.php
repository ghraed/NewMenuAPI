<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffShift>
 */
class StaffShiftFactory extends Factory
{
    protected $model = StaffShift::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'user_id' => User::factory()->staff(),
            'shift_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'position' => 'waiter',
            'status' => 'scheduled',
            'notes' => null,
        ];
    }
}
