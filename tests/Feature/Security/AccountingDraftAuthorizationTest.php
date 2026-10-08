<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class AccountingDraftAuthorizationTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_same_tenant_accountant_can_save_an_accounting_draft(): void
    {
        $restaurant = $this->createRestaurant(attributes: [
            'name' => 'QA_RUN_ACCOUNTING_AUTH',
        ]);
        $accountant = User::factory()->accountant()->attachedToRestaurant($restaurant)->create();
        $order = $this->createConfirmedOrder($restaurant);

        Sanctum::actingAs($accountant);

        $this->patchJson("/api/orders/{$order->id}/accounting-draft", [
            'vat_rate' => 10,
            'service_charge_rate' => 5,
            'discount_type' => 'fixed',
            'discount_value' => 1,
        ])->assertOk()
            ->assertJsonPath('order.id', $order->id)
            ->assertJsonPath('order.invoice.vat_rate', '10.00')
            ->assertJsonPath('order.invoice.service_charge_rate', '5.00');
    }

    public function test_non_accounting_roles_cannot_save_accounting_drafts(): void
    {
        $restaurant = $this->createRestaurant(attributes: [
            'name' => 'QA_RUN_ACCOUNTING_NEGATIVE_ROLES',
        ]);
        $order = $this->createConfirmedOrder($restaurant);
        $users = [
            User::factory()->staff()->attachedToRestaurant($restaurant)->create(),
            User::factory()->chef()->attachedToRestaurant($restaurant)->create(),
            User::factory()->stockManager()->attachedToRestaurant($restaurant)->create(),
        ];

        foreach ($users as $user) {
            Sanctum::actingAs($user);

            $this->patchJson("/api/orders/{$order->id}/accounting-draft", [
                'vat_rate' => 10,
            ])->assertForbidden();
        }
    }

    public function test_accountant_cannot_save_another_tenants_accounting_draft(): void
    {
        $restaurant = $this->createRestaurant(attributes: [
            'name' => 'QA_RUN_ACCOUNTANT_TENANT',
        ]);
        $otherRestaurant = $this->createRestaurant(attributes: [
            'name' => 'QA_RUN_FOREIGN_ACCOUNTING',
        ]);
        $accountant = User::factory()->accountant()->attachedToRestaurant($restaurant)->create();
        $foreignOrder = $this->createConfirmedOrder($otherRestaurant);

        Sanctum::actingAs($accountant);

        $this->patchJson("/api/orders/{$foreignOrder->id}/accounting-draft", [
            'vat_rate' => 10,
        ])->assertNotFound();
    }

    private function createConfirmedOrder(Restaurant $restaurant): Order
    {
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $restaurant->tables()->value('id'),
            'order_number' => 'QA_RUN_'.Str::upper(Str::random(8)),
            'status' => Order::STATUS_STAFF_CONFIRMED,
            'guest_name' => 'QA_RUN_GUEST',
            'table_reference' => 'T01',
            'subtotal' => '20.00',
            'taxable_subtotal' => '20.00',
            'total' => '20.00',
            'confirmed_at' => now(),
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'dish_name' => 'QA_RUN_ITEM',
            'unit_price' => '20.00',
            'quantity' => 1,
            'line_subtotal' => '20.00',
        ]);

        return $order;
    }
}
