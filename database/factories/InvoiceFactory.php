<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('#####'),
            'invoice_date' => now()->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => '100.00',
            'total' => '100.00',
            'notes' => null,
            'paid_at' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => Invoice::STATUS_ISSUED,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }
}

