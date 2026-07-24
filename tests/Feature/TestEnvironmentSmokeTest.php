<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishIngredient;
use App\Models\Expense;
use App\Models\Feature;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayrollPeriod;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\RoomPlan;
use App\Models\RoomPlanItem;
use App\Models\StaffShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TestEnvironmentSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_environment_is_locked_down(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', Config::get('database.default'));
        $this->assertStringContainsString('test', (string) Config::get('database.connections.mysql.database'));
        $this->assertSame('array', Config::get('mail.default'));
        $this->assertSame('sync', Config::get('queue.default'));
        $this->assertSame('array', Config::get('cache.default'));
        $this->assertSame('array', Config::get('session.driver'));
        $this->assertSame('null', Config::get('broadcasting.default'));
        $this->assertSame('local', Config::get('filesystems.default'));
        $this->assertStringContainsString('framework/testing/disks/public', (string) Config::get('filesystems.disks.public.root'));
    }

    public function test_core_factories_can_build_isolated_tenant_data(): void
    {
        $owner = User::factory()->admin()->create();
        $restaurant = Restaurant::factory()->for($owner, 'user')->create();
        $staff = User::factory()->staff()->attachedToRestaurant($restaurant)->create();

        $feature = Feature::factory()->create(['key' => 'table_reservations']);
        RestaurantFeature::factory()->for($restaurant)->for($feature)->create();

        $roomPlan = RoomPlan::factory()->for($restaurant)->create();
        $table = RestaurantTable::factory()->for($restaurant)->create(['name' => 'T01']);
        $roomPlanItem = RoomPlanItem::factory()
            ->for($roomPlan)
            ->for($table, 'restaurantTable')
            ->create([
                'type' => RoomPlanItem::TYPE_TABLE,
                'label' => 'T01',
            ]);

        $dish = Dish::factory()->for($restaurant)->published()->create();
        $ingredient = \App\Models\Ingredient::factory()->for($restaurant)->create();
        $recipe = DishIngredient::factory()->for($dish)->for($ingredient)->create();

        $order = Order::factory()->for($restaurant)->for($table, 'restaurantTable')->create([
            'table_reference' => $table->name,
            'subtotal' => '12.50',
            'total' => '12.50',
        ]);
        $orderItem = OrderItem::factory()->for($order)->for($dish)->create([
            'dish_name' => $dish->name,
        ]);

        $invoice = Invoice::factory()->for($restaurant)->create();
        $reservation = Reservation::factory()
            ->for($restaurant)
            ->for($roomPlan)
            ->for($roomPlanItem)
            ->create();
        $expense = Expense::factory()->for($restaurant)->create();
        $payrollPeriod = PayrollPeriod::factory()->for($restaurant)->for($staff, 'employee')->create();
        $shift = StaffShift::factory()->for($restaurant)->for($staff)->create();

        $this->assertInstanceOf(Restaurant::class, $restaurant);
        $this->assertSame($restaurant->id, $dish->restaurant_id);
        $this->assertSame($restaurant->id, $ingredient->restaurant_id);
        $this->assertSame($dish->id, $recipe->dish_id);
        $this->assertSame($order->id, $orderItem->order_id);
        $this->assertSame($roomPlanItem->id, $reservation->room_plan_item_id);
        $this->assertSame($restaurant->id, $expense->restaurant_id);
        $this->assertSame($staff->id, $payrollPeriod->employee_id);
        $this->assertSame($staff->id, $shift->user_id);

        Storage::disk('public')->put('smoke-test/example.txt', 'ok');
        $this->assertTrue(Storage::disk('public')->exists('smoke-test/example.txt'));
    }
}
