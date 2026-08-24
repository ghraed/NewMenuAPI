<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryShareContactApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_contacts_are_created_listed_and_scoped_to_the_current_restaurant(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableInventory($restaurant);

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/inventory/share-contacts', [
            'name' => 'Fresh Foods',
            'phone' => '+961 70 123 456',
        ]);
        $createResponse->assertCreated()
            ->assertJsonPath('contact.name', 'Fresh Foods')
            ->assertJsonPath('contact.phone', '+961 70 123 456');
        $contactId = (int) $createResponse->json('contact.id');

        $this->patchJson("/api/inventory/share-contacts/{$contactId}", [
            'name' => 'Fresh Foods Beirut',
            'phone' => '+961 71 222 333',
        ])->assertOk()
            ->assertJsonPath('contact.name', 'Fresh Foods Beirut')
            ->assertJsonPath('contact.phone', '+961 71 222 333');

        $this->getJson('/api/inventory/share-contacts')
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.name', 'Fresh Foods Beirut');

        $otherAdmin = User::factory()->admin()->create();
        $otherRestaurant = $this->createRestaurant($otherAdmin);
        $this->enableInventory($otherRestaurant);
        Sanctum::actingAs($otherAdmin);

        $this->getJson('/api/inventory/share-contacts')
            ->assertOk()
            ->assertJsonCount(0, 'contacts');

        $this->patchJson("/api/inventory/share-contacts/{$contactId}", [
            'name' => 'Unauthorized edit',
            'phone' => '+961 70 999 999',
        ])->assertNotFound();
        $this->deleteJson("/api/inventory/share-contacts/{$contactId}")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/inventory/share-contacts/{$contactId}")->assertOk();
        $this->assertDatabaseMissing('inventory_share_contacts', ['id' => $contactId]);
    }

    public function test_contact_requires_a_valid_whatsapp_phone_number(): void
    {
        $admin = User::factory()->admin()->create();
        $restaurant = $this->createRestaurant($admin);
        $this->enableInventory($restaurant);
        Sanctum::actingAs($admin);

        $this->postJson('/api/inventory/share-contacts', [
            'name' => 'Supplier',
            'phone' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    private function createRestaurant(User $owner): Restaurant
    {
        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Share Contacts '.Str::upper(Str::random(4)),
            'slug' => 'share-contacts-'.Str::lower(Str::random(8)),
        ]);
    }

    private function enableInventory(Restaurant $restaurant): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => 'inventory'],
            ['name' => 'Inventory', 'category' => 'Testing', 'is_active_by_default' => false]
        );

        RestaurantFeature::query()->create([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $feature->id,
            'enabled' => true,
        ]);
    }
}
