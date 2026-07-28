<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsRestaurantOrderFlow;
use Tests\TestCase;

class InvoiceSplitApiTest extends TestCase
{
    use BuildsRestaurantOrderFlow;
    use RefreshDatabase;

    public function test_guest_can_split_selected_items_with_mixed_quantities_and_more_people_than_items(): void
    {
        $restaurant = $this->createRestaurant();
        $dishA = $this->createDish($restaurant, 'Split A', 3.50);
        $dishB = $this->createDish($restaurant, 'Split B', 4.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dishA->id, 'quantity' => 2],
                ['dish_id' => $dishB->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $orderItems = OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $orderItems);

        $response = $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 4,
            'people' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => $orderItems[0]->id, 'quantity' => 1],
                    ],
                ],
                [
                    'person_index' => 2,
                    'items' => [
                        ['order_item_id' => $orderItems[0]->id, 'quantity' => 1],
                        ['order_item_id' => $orderItems[1]->id, 'quantity' => 1],
                    ],
                ],
            ],
        ], $this->guestHeaders($token));

        $response->assertOk()
            ->assertJsonPath('invoice_split.mode', 'by_person_order')
            ->assertJsonPath('invoice_split.split_count', 4)
            ->assertJsonPath('invoice_split.is_complete', true)
            ->assertJsonPath('invoice_split.breakdown.0.amount', '3.50')
            ->assertJsonPath('invoice_split.breakdown.1.amount', '7.50')
            ->assertJsonPath('invoice_split.breakdown.2.amount', '0.00')
            ->assertJsonPath('invoice_split.breakdown.3.amount', '0.00');
    }

    public function test_split_validation_rejects_zero_people_and_duplicate_assignments_and_tracks_unassigned_items(): void
    {
        $restaurant = $this->createRestaurant();
        $dish = $this->createDish($restaurant, 'Shared Plate', 5.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurant, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 2],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurant->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        $orderItem = OrderItem::query()->where('order_id', $orderId)->firstOrFail();

        $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 0,
        ], $this->guestHeaders($token))->assertStatus(422)
            ->assertJsonValidationErrors(['split_count']);

        $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 2,
            'people' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => $orderItem->id, 'quantity' => 2],
                    ],
                ],
                [
                    'person_index' => 2,
                    'items' => [
                        ['order_item_id' => $orderItem->id, 'quantity' => 1],
                    ],
                ],
            ],
        ], $this->guestHeaders($token))->assertStatus(422)
            ->assertJsonValidationErrors(['people']);

        $partialSplit = $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 2,
            'people' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => $orderItem->id, 'quantity' => 1],
                    ],
                ],
            ],
        ], $this->guestHeaders($token));

        $partialSplit->assertOk()
            ->assertJsonPath('invoice_split.is_complete', false)
            ->assertJsonPath('invoice_split.breakdown.0.amount', '5.00')
            ->assertJsonPath('invoice_split.breakdown.1.amount', '0.00')
            ->assertJsonPath('invoice_split.remaining_items.0.remaining_quantity', 1)
            ->assertJsonPath('invoice_split.remaining_items.0.line_subtotal', '5.00');

        $repeatSplit = $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'by_person_order',
            'split_count' => 2,
            'people' => [
                [
                    'person_index' => 1,
                    'items' => [
                        ['order_item_id' => $orderItem->id, 'quantity' => 1],
                    ],
                ],
            ],
        ], $this->guestHeaders($token));

        $repeatSplit->assertOk()
            ->assertJsonPath('invoice_split.remaining_items.0.remaining_quantity', 1);
    }

    public function test_split_endpoints_are_tenant_isolated_and_require_authorization(): void
    {
        $restaurantA = $this->createRestaurant();
        $restaurantB = $this->createRestaurant();
        $dish = $this->createDish($restaurantA, 'Split Protected', 6.00);
        ['session' => $session, 'token' => $token] = $this->openGuestAccess($restaurantA, 1);

        $orderId = $this->postJson("/api/table-session/{$session->id}/order", [
            'items' => [
                ['dish_id' => $dish->id, 'quantity' => 1],
            ],
        ], $this->guestHeaders($token))->json('order.id');

        $this->assertIsInt($orderId);

        Sanctum::actingAs($restaurantA->user);
        $this->postJson("/api/orders/{$orderId}/confirm")->assertOk();

        auth()->forgetGuards();
        $this->getJson("/api/table-sessions/{$session->id}/invoice-split")->assertStatus(401);
        $this->patchJson("/api/table-session/{$session->id}/invoice-split", [
            'mode' => 'equal',
            'split_count' => 2,
        ])->assertForbidden();

        Sanctum::actingAs($restaurantB->user);
        $this->getJson("/api/table-sessions/{$session->id}/invoice-split")->assertStatus(404);
    }
}
