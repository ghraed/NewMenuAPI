<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 4, 25);
        $quantity = fake()->numberBetween(1, 4);

        return [
            'order_id' => Order::factory(),
            'dish_id' => Dish::factory(),
            'dish_name' => Str::title(fake()->words(2, true)),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_subtotal' => number_format($unitPrice * $quantity, 2, '.', ''),
            'status' => 'normal',
            'compensation_type' => 'none',
            'compensation_reason' => null,
            'complaint_category' => null,
            'operational_loss_category' => null,
            'adjustment_action_type' => null,
            'compensation_note' => null,
            'approved_by_staff_id' => null,
            'approved_by_staff_name' => null,
            'approved_by_staff_role' => null,
            'approved_at' => null,
            'original_unit_price' => $unitPrice,
            'final_unit_price' => $unitPrice,
            'partial_discount_percentage' => null,
            'partial_discount_type' => null,
            'partial_discount_value' => null,
            'is_complimentary' => false,
            'accounting_bucket' => null,
            'customer_satisfaction_rating' => null,
            'evidence_photo_url' => null,
        ];
    }
}
