<?php

namespace Database\Factories;

use App\Models\PayrollPeriod;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'employee_id' => User::factory()->staff(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'period_type' => PayrollPeriod::TYPE_REGULAR,
            'adjustment_of_period_id' => null,
            'status' => PayrollPeriod::STATUS_DRAFT,
            'approved_at' => null,
            'paid_at' => null,
            'processed_by' => null,
            'notes' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PayrollPeriod::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function adjustment(PayrollPeriod $period): static
    {
        return $this->state(fn (): array => [
            'restaurant_id' => $period->restaurant_id,
            'employee_id' => $period->employee_id,
            'period_type' => PayrollPeriod::TYPE_ADJUSTMENT,
            'adjustment_of_period_id' => $period->id,
        ]);
    }
}

