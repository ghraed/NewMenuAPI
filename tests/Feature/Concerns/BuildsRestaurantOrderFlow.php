<?php

namespace Tests\Feature\Concerns;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Models\RestaurantTable;
use App\Models\TableSession;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait BuildsRestaurantOrderFlow
{
    /**
     * @param array<int, string> $featureKeys
     * @param array<string, mixed> $attributes
     */
    private function createRestaurant(
        ?User $user = null,
        array $featureKeys = [],
        array $attributes = []
    ): Restaurant {
        $owner = $user ?? User::factory()->admin()->create();

        $restaurant = Restaurant::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Invoice Test Restaurant '.Str::upper(Str::random(3)),
            'slug' => 'invoice-test-'.Str::lower(Str::random(8)),
            'description' => 'Restaurant for invoice test coverage',
            'address' => 'Beirut',
            'currency' => 'USD',
            'other_currency' => 'LBP',
            'dollar_rate' => '89500.75',
        ], $attributes));

        foreach (range(1, 10) as $number) {
            RestaurantTable::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => sprintf('T%02d', $number),
                'is_active' => true,
                'seats' => 4,
            ]);
        }

        foreach (array_unique(array_merge([
            'qr_menu',
            'table_ordering',
            'waiter_call',
            'request_bill',
            'realtime_staff_orders',
            'finance_dashboard',
            'dish_profitability',
            'vat_invoices',
            'expense_management',
            'invoice_splitting',
        ], $featureKeys)) as $featureKey) {
            $this->enableFeature($restaurant, $featureKey);
        }

        config(['app.guest_restaurant_slug' => $restaurant->slug]);

        return $restaurant->fresh('tables', 'user');
    }

    private function createDish(
        Restaurant $restaurant,
        string $name,
        float $price,
        string $status = 'published',
        ?string $nameAr = null
    ): Dish {
        return Dish::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'name' => $name,
            'name_ar' => $nameAr,
            'description' => $name.' description',
            'price' => $price,
            'currency' => 'USD',
            'category' => 'Main',
            'status' => $status,
        ]);
    }

    private function createStaffUser(Restaurant $restaurant, array $tableNames = []): User
    {
        $staff = User::factory()->staff()->create();
        $restaurant->staffUsers()->attach($staff->id);

        if ($tableNames !== []) {
            $tableIds = $restaurant->tables()
                ->whereIn('name', $tableNames)
                ->pluck('id')
                ->all();

            $staff->assignedTables()->sync($tableIds);
        }

        return $staff;
    }

    /**
     * @return array{session:TableSession, token:string}
     */
    private function openGuestAccess(Restaurant $restaurant, int $tableNumber): array
    {
        $session = $this->openGuestTable($restaurant, $tableNumber);
        $token = $this->verifyCurrentTablePin($tableNumber, $this->activeSessionPin());

        return [
            'session' => $session,
            'token' => $token,
        ];
    }

    private function openGuestTable(Restaurant $restaurant, int $tableNumber): TableSession
    {
        config(['app.guest_restaurant_slug' => $restaurant->slug]);

        $table = $restaurant->tables()->orderBy('name')->get()->values()->get($tableNumber - 1);
        $this->assertNotNull($table);

        Sanctum::actingAs($restaurant->user);
        $this->postJson('/api/table-sessions/activate', [
            'table_id' => $table->id,
        ])->assertOk();

        return TableSession::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('table_number', $tableNumber)
            ->latest('id')
            ->firstOrFail();
    }

    private function activeSessionPin(): string
    {
        $session = TableSession::query()->latest('id')->firstOrFail();
        $pin = app(GuestMenuSessionService::class)->currentPlainPin($session);

        $this->assertIsString($pin);

        return $pin;
    }

    private function verifyCurrentTablePin(int $tableNumber, string $pin): string
    {
        $response = $this->postJson("/api/menu/table/{$tableNumber}/verify-pin", [
            'pin' => $pin,
        ], $this->guestHeaders());

        $response->assertOk();

        return (string) $response->json('guest_access.token');
    }

    /**
     * @return array<string, string>
     */
    private function guestHeaders(?string $token = null): array
    {
        return array_filter([
            'X-Guest-Device-Id' => 'invoice-test-device',
            'X-Guest-Access-Token' => $token,
        ]);
    }

    private function enableFeature(Restaurant $restaurant, string $featureKey): void
    {
        $feature = Feature::query()->updateOrCreate(
            ['key' => $featureKey],
            [
                'name' => Str::title(str_replace('_', ' ', $featureKey)),
                'description' => 'Enabled in tests',
                'category' => 'Testing',
                'is_active_by_default' => false,
            ]
        );

        RestaurantFeature::query()->updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'feature_id' => $feature->id,
            ],
            [
                'enabled' => true,
            ]
        );
    }
}
