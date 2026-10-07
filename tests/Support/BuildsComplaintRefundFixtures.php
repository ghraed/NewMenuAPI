<?php

namespace Tests\Support;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait BuildsComplaintRefundFixtures
{
    private string $prefix;

    private User $admin;

    private Restaurant $restaurant;

    private Order $order;

    private Invoice $invoice;

    private OrderItem $item;

    private Dish $dish;

    private Ingredient $ingredient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'QA_RUN_'.(getenv('QA_RUN_ID') ?: 'refund').'_'.bin2hex(random_bytes(4));
        $this->admin = User::factory()->admin()->create(['name' => $this->prefix, 'email' => $this->prefix.'@example.invalid']);
        $this->restaurant = Restaurant::factory()->create(['user_id' => $this->admin->id, 'name' => $this->prefix, 'slug' => strtolower($this->prefix)]);
        foreach (['table_ordering', 'finance_dashboard', 'expense_management', 'inventory', 'ingredient_stock_deduction'] as $key) {
            $feature = Feature::query()->firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'Testing', 'is_active_by_default' => false]);
            RestaurantFeature::query()->create(['restaurant_id' => $this->restaurant->id, 'feature_id' => $feature->id, 'enabled' => true]);
        }
        $this->dish = Dish::factory()->published()->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix, 'price' => '10.00']);
        $this->ingredient = Ingredient::factory()->pieces(5)->create(['restaurant_id' => $this->restaurant->id, 'name' => $this->prefix]);
        DishIngredient::query()->create(['dish_id' => $this->dish->id, 'ingredient_id' => $this->ingredient->id, 'quantity' => '1.000', 'unit' => Ingredient::UNIT_PIECE]);
        $this->invoice = Invoice::factory()->paid()->create(['restaurant_id' => $this->restaurant->id, 'invoice_number' => $this->prefix, 'total' => '10.00', 'subtotal' => '10.00', 'invoice_date' => now()->toDateString()]);
        $this->order = $this->sale();
        $this->item = $this->order->items()->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    private function sale(): Order
    {
        $order = Order::factory()->accounted()->create(['restaurant_id' => $this->restaurant->id, 'restaurant_table_id' => null, 'invoice_number' => $this->invoice->invoice_number, 'order_number' => $this->prefix.'_'.bin2hex(random_bytes(3)), 'guest_name' => $this->prefix, 'guest_phone' => null, 'guest_email' => null, 'subtotal' => '10.00', 'total' => '10.00', 'payment_method' => 'cash']);
        OrderItem::factory()->create(['order_id' => $order->id, 'dish_id' => $this->dish->id, 'dish_name' => $this->prefix, 'unit_price' => '10.00', 'line_subtotal' => '10.00', 'quantity' => 1]);

        return $order;
    }

    private function draft(string $amount = '8.00', array $extra = [], ?Order $order = null): int
    {
        $order ??= $this->order;

        return $this->postJson("/api/pos/orders/{$order->id}/complaint-adjustments", array_merge([
            'complaint_reason' => $this->prefix, 'refund_amount' => $amount,
            'affected_item_ids' => [$order->items()->firstOrFail()->id],
        ], $extra))->assertCreated()->json('adjustment.id');
    }

    private function approve(int $id): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/pos/complaint-adjustments/{$id}/post");
    }

    private function assertReport(int $refundCents, int $revenueCents): void
    {
        $day = now()->toDateString();
        $report = $this->getJson("/api/admin/finance/profit-loss?date_from={$day}&date_to={$day}")->assertOk();
        $this->assertSame($refundCents, (int) round($report->json('complaint_refunds') * 100));
        $this->assertSame($revenueCents, (int) round($report->json('revenue') * 100));
        $this->assertSame($revenueCents, (int) round($report->json('net_profit') * 100));
        $dashboard = $this->getJson("/api/admin/finance/dashboard-metrics?date_from={$day}&date_to={$day}")->assertOk();
        $this->assertSame($revenueCents, (int) round($dashboard->json('kpis.revenue.value') * 100));
    }
}
