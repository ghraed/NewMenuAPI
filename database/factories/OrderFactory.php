<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => Restaurant::factory(),
            'restaurant_table_id' => RestaurantTable::factory(),
            'table_session_id' => null,
            'order_number' => 'ORD-'.fake()->unique()->numerify('#####'),
            'invoice_number' => null,
            'status' => Order::STATUS_PENDING_STAFF_CONFIRMATION,
            'kitchen_status' => Order::KITCHEN_STATUS_NEW,
            'guest_name' => fake()->name(),
            'guest_phone' => fake()->phoneNumber(),
            'guest_email' => fake()->safeEmail(),
            'table_reference' => 'T'.fake()->numerify('##'),
            'notes' => null,
            'vat_rate' => '0.00',
            'subtotal' => '0.00',
            'discount_type' => null,
            'discount_value' => '0.00',
            'discount_amount' => '0.00',
            'taxable_subtotal' => '0.00',
            'vat_amount' => '0.00',
            'total' => '0.00',
            'confirmed_by' => null,
            'confirmed_at' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'accounted_by' => null,
            'accounted_at' => null,
            'kitchen_started_at' => null,
            'kitchen_ready_at' => null,
            'kitchen_completed_at' => null,
            'kitchen_updated_by' => null,
        ];
    }

    public function staffConfirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function accounted(): static
    {
        return $this->state(fn (): array => [
            'status' => Order::STATUS_ACCOUNTED,
            'accounted_at' => now(),
        ]);
    }
}

