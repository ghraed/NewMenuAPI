<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $prefix = 'QA_RUN_20261006_'.bin2hex(random_bytes(4));
        $this->admin = User::factory()->admin()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $this->restaurant = Restaurant::factory()->for($this->admin)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);
        $feature = Feature::factory()->create(['key' => 'realtime_staff_orders', 'name' => 'QA_RUN_20261006_History', 'is_active_by_default' => false]);
        RestaurantFeature::create(['restaurant_id' => $this->restaurant->id, 'feature_id' => $feature->id, 'enabled' => true]);
        Sanctum::actingAs($this->admin);
    }

    private function order(array $attributes = [], ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= $this->restaurant;

        return Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurant->tables()->firstOrFail()->id,
            'order_number' => 'QA_RUN_20261006_'.bin2hex(random_bytes(4)),
            'guest_name' => 'QA_RUN_20261006_Guest', 'guest_email' => 'QA_RUN_guest@example.invalid', 'guest_phone' => null,
            'created_at' => '2026-01-15 12:00:00',
            ...$attributes,
        ]);
    }

    public function test_admin_history_includes_every_status_and_excludes_other_tenants(): void
    {
        $orders = [];
        foreach ([Order::STATUS_PENDING_STAFF_CONFIRMATION, Order::STATUS_STAFF_CONFIRMED, Order::STATUS_ACCOUNTED, Order::STATUS_STAFF_CANCELLED] as $status) {
            $orders[] = $this->order(['status' => $status]);
        }
        $prefix = 'QA_RUN_20261006_foreign_'.bin2hex(random_bytes(4));
        $owner = User::factory()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $foreign = Restaurant::factory()->for($owner)->create(['name' => $prefix, 'slug' => strtolower($prefix)]);
        $foreignOrder = $this->order([], $foreign);
        $response = $this->getJson('/api/orders/history?date_from=2026-01-15&date_to=2026-01-15&timezone=UTC')->assertOk();
        $this->assertEqualsCanonicalizing(array_map(fn (Order $order) => $order->id, $orders), array_column($response->json('orders'), 'id'));
        $this->getJson("/api/orders/{$foreignOrder->id}")->assertNotFound();
    }

    public function test_settled_order_details_include_items_and_totals_even_on_an_old_date(): void
    {
        $order = $this->order(['status' => Order::STATUS_ACCOUNTED, 'created_at' => '2025-12-01 12:00:00', 'total' => '9.25', 'subtotal' => '9.25']);
        $dish = Dish::factory()->for($this->restaurant)->create(['name' => 'QA_RUN_20261006_Dish']);
        OrderItem::factory()->for($order)->for($dish)->create(['dish_name' => $dish->name, 'quantity' => 1, 'unit_price' => '9.25', 'line_subtotal' => '9.25']);

        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.status', Order::STATUS_ACCOUNTED)->assertJsonPath('order.items.0.dish_name', $dish->name)
            ->assertJsonPath('order.invoice.total', '9.25');
    }

    public function test_today_defaults_to_current_day_and_keeps_settled_orders(): void
    {
        $today = $this->order(['status' => Order::STATUS_ACCOUNTED, 'accounted_at' => '2026-01-15 12:00:00']);
        $this->order(['created_at' => '2026-01-14 12:00:00']);
        $this->getJson('/api/orders/today?timezone=UTC')->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $today->id);
    }

    public function test_date_range_includes_confirmation_payment_and_cancellation_activity(): void
    {
        $orders = [
            $this->order(['created_at' => '2026-01-12 12:00:00', 'confirmed_at' => '2026-01-15 12:00:00', 'status' => Order::STATUS_STAFF_CONFIRMED]),
            $this->order(['created_at' => '2026-01-12 12:00:00', 'accounted_at' => '2026-01-15 12:00:00', 'status' => Order::STATUS_ACCOUNTED]),
            $this->order(['created_at' => '2026-01-12 12:00:00', 'cancelled_at' => '2026-01-15 12:00:00', 'status' => Order::STATUS_STAFF_CANCELLED]),
        ];
        $this->order(['created_at' => '2026-01-16 12:00:00']);
        $response = $this->getJson('/api/orders/history?date_from=2026-01-15&date_to=2026-01-15')->assertOk();
        $this->assertEqualsCanonicalizing(array_map(fn (Order $order) => $order->id, $orders), array_column($response->json('orders'), 'id'));
    }

    public function test_date_filters_respect_local_timezone_midnight_boundaries(): void
    {
        $this->order(['created_at' => '2026-01-14 21:59:59']);
        $start = $this->order(['created_at' => '2026-01-14 22:00:00']);
        $end = $this->order(['created_at' => '2026-01-15 21:59:59']);
        $this->order(['created_at' => '2026-01-15 22:00:00']);
        $response = $this->getJson('/api/orders/today?date=2026-01-15&timezone=Asia%2FBeirut')->assertOk();
        $this->assertEqualsCanonicalizing([$start->id, $end->id], array_column($response->json('orders'), 'id'));
    }

    public function test_history_supports_an_open_ended_date_range(): void
    {
        $early = $this->order(['created_at' => '2026-01-10 12:00:00']);
        $late = $this->order(['created_at' => '2026-01-20 12:00:00']);
        $this->getJson('/api/orders/history?date_to=2026-01-15')->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $early->id);
        $this->getJson('/api/orders/history?date_from=2026-01-15')->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $late->id);
    }

    public function test_staff_history_and_details_follow_table_assignments_and_event_access(): void
    {
        $prefix = 'QA_RUN_20261006_staff_'.bin2hex(random_bytes(4));
        $staff = User::factory()->staff()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $this->restaurant->staffUsers()->attach($staff->id);
        $tables = $this->restaurant->tables()->orderBy('id')->get();
        $staff->assignedTables()->attach($tables[0]->id);
        $assigned = $this->order(['status' => Order::STATUS_ACCOUNTED, 'restaurant_table_id' => $tables[0]->id]);
        $otherTable = $this->order(['restaurant_table_id' => $tables[1]->id]);
        $event = $this->order(['restaurant_table_id' => null, 'table_reference' => 'EVENT-QA_RUN_20261006']);
        $tableless = $this->order(['restaurant_table_id' => null, 'table_reference' => 'QA_RUN_NO_TABLE']);
        Sanctum::actingAs($staff);

        foreach (['history', 'today'] as $endpoint) {
            $response = $this->getJson("/api/orders/{$endpoint}?date=2026-01-15")->assertOk();
            $this->assertEqualsCanonicalizing([$assigned->id, $event->id], array_column($response->json('orders'), 'id'));
        }
        $this->getJson("/api/orders/{$assigned->id}")->assertOk();
        $this->getJson("/api/orders/{$event->id}")->assertOk();
        $this->getJson("/api/orders/{$otherTable->id}")->assertForbidden();
        $this->getJson("/api/orders/{$tableless->id}")->assertForbidden();
    }

    public function test_disabled_staff_order_feature_denies_history_and_details(): void
    {
        $order = $this->order();
        RestaurantFeature::where('restaurant_id', $this->restaurant->id)->update(['enabled' => false]);
        foreach (['history', 'today', $order->id] as $endpoint) {
            $this->getJson("/api/orders/{$endpoint}")->assertNotFound()
                ->assertJsonPath('message', 'Feature [realtime_staff_orders] is disabled for this restaurant.');
        }
    }

    public function test_staff_without_assigned_tables_only_sees_shared_event_orders(): void
    {
        $prefix = 'QA_RUN_20261006_unassigned_'.bin2hex(random_bytes(4));
        $staff = User::factory()->staff()->create(['name' => $prefix, 'email' => $prefix.'@example.invalid']);
        $this->restaurant->staffUsers()->attach($staff->id);
        $this->order(['status' => Order::STATUS_ACCOUNTED]);
        $event = $this->order(['restaurant_table_id' => null, 'table_reference' => 'EVENT-QA_RUN_20261006']);
        Sanctum::actingAs($staff);

        $this->getJson('/api/orders/history')->assertOk()->assertJsonCount(1, 'orders')->assertJsonPath('orders.0.id', $event->id);
    }

    public static function invalidFilters(): array
    {
        return [
            ['date=not-a-date', 'date'],
            ['date_from=2026-01-16&date_to=2026-01-15', 'date_to'],
            ['timezone=QA_RUN_Invalid', 'timezone'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_history_filters_return_validation_errors(string $query, string $field): void
    {
        $this->getJson('/api/orders/history?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function unsupportedRoles(): array
    {
        return [[User::ROLE_CHEF], [User::ROLE_STOCK_MANAGER], [User::ROLE_ACCOUNTANT]];
    }

    #[DataProvider('unsupportedRoles')]
    public function test_other_roles_cannot_use_staff_history_routes(string $role): void
    {
        $this->admin->update(['role' => $role]);
        foreach (['history', 'today'] as $endpoint) {
            $this->getJson("/api/orders/{$endpoint}")->assertForbidden();
        }
    }

    public function test_unauthenticated_user_cannot_read_history(): void
    {
        $this->refreshApplication();
        $this->getJson('/api/orders/history')->assertUnauthorized();
        $this->getJson('/api/orders/today')->assertUnauthorized();
    }
}
