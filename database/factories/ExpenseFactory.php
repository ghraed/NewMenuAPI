<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Restaurant;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'vendor_id' => Vendor::factory(),
            'payroll_period_id' => null,
            'expense_date' => now()->toDateString(),
            'amount_cents' => fake()->numberBetween(1_000, 100_000),
            'tax_amount_cents' => fake()->numberBetween(0, 10_000),
            'currency' => 'USD',
            'status' => Expense::STATUS_DRAFT,
            'payment_method' => 'cash',
            'reference_no' => 'EXP-'.fake()->unique()->numerify('#####'),
            'description' => fake()->sentence(),
            'notes' => null,
            'due_date' => now()->addDays(14)->toDateString(),
            'paid_at' => null,
            'created_by' => null,
            'approved_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => Expense::STATUS_APPROVED,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => Expense::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}

