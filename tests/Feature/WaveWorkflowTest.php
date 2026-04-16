<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\TableWave;
use App\Models\User;
use App\Services\GuestMenuSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_send_a_wave_for_a_valid_table_and_duplicate_pending_waves_are_reused(): void
    {
        $restaurant = $this->createRestaurant();
        $session = $this->openGuestTable(2);
        $token = $this->verifyCurrentTablePin(2, $this->activeSessionPin());

        $firstResponse = $this->postJson("/api/table-session/{$session->id}/call-waiter", [], $this->guestHeaders($token));

        $firstResponse->assertCreated()
            ->assertJsonPath('wave.status', TableWave::STATUS_PENDING)
            ->assertJsonPath('wave.table_reference', 'T02');

        $this->assertDatabaseHas('table_waves', [
            'restaurant_id' => $restaurant->id,
            'table_reference' => 'T02',
            'status' => TableWave::STATUS_PENDING,
        ]);

        $secondResponse = $this->postJson("/api/table-session/{$session->id}/call-waiter", [], $this->guestHeaders($token));

        $secondResponse->assertOk()
            ->assertJsonPath('wave.status', TableWave::STATUS_PENDING)
            ->assertJsonPath('wave.table_reference', 'T02');

        $this->assertSame(1, TableWave::query()->count());
    }

    public function test_staff_can_only_see_and_resolve_waves_for_assigned_tables(): void
    {
        $restaurant = $this->createRestaurant();
        $otherRestaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T03']);
        $ownedWave = $this->createPendingWave($restaurant, 'T03');
        $this->createPendingWave($restaurant, 'T04');
        $this->createPendingWave($otherRestaurant, 'T03');

        Sanctum::actingAs($staff);

        $listResponse = $this->getJson('/api/waves/pending');

        $listResponse->assertOk()
            ->assertJsonCount(1, 'waves')
            ->assertJsonPath('waves.0.id', $ownedWave->id)
            ->assertJsonPath('waves.0.table_reference', 'T03');

        $resolveResponse = $this->postJson("/api/waves/{$ownedWave->id}/resolve");

        $resolveResponse->assertOk()
            ->assertJsonPath('wave.status', TableWave::STATUS_RESOLVED)
            ->assertJsonPath('wave.resolved_by.id', $staff->id)
            ->assertJsonPath('wave.table_reference', 'T03');

        $this->assertDatabaseHas('table_waves', [
            'id' => $ownedWave->id,
            'status' => TableWave::STATUS_RESOLVED,
            'resolved_by' => $staff->id,
        ]);
    }

    public function test_staff_cannot_resolve_waves_for_unassigned_tables(): void
    {
        $restaurant = $this->createRestaurant();
        $staff = $this->createStaffUser($restaurant, ['T01']);
        $wave = $this->createPendingWave($restaurant, 'T05');

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/waves/{$wave->id}/resolve");

        $response->assertForbidden();

        $this->assertDatabaseHas('table_waves', [
            'id' => $wave->id,
            'status' => TableWave::STATUS_PENDING,
            'resolved_by' => null,
        ]);
    }

    private function createRestaurant(?User $user = null): Restaurant
    {
        $owner = $user ?? User::factory()->admin()->create();

        return Restaurant::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'name' => 'Wave Workflow Restaurant '.Str::upper(Str::random(3)),
            'slug' => 'wave-workflow-'.Str::lower(Str::random(8)),
            'description' => 'Restaurant for wave workflow tests',
            'address' => 'Beirut',
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

    private function createPendingWave(Restaurant $restaurant, string $tableName): TableWave
    {
        $tableId = $restaurant->tables()->where('name', $tableName)->value('id');

        return TableWave::query()->create([
            'uuid' => (string) Str::uuid(),
            'restaurant_id' => $restaurant->id,
            'restaurant_table_id' => $tableId,
            'status' => TableWave::STATUS_PENDING,
            'table_reference' => $tableName,
        ]);
    }

    private function openGuestTable(int $tableNumber): TableSession
    {
        $this->getJson("/api/menu/table/{$tableNumber}")->assertOk();

        return TableSession::query()
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

    private function guestHeaders(?string $token = null): array
    {
        return array_filter([
            'X-Guest-Device-Id' => 'wave-workflow-device',
            'X-Guest-Access-Token' => $token,
        ]);
    }
}
