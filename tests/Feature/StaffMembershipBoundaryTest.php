<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffMembershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_staff_membership_is_intentionally_rejected_without_replacing_first(): void
    {
        $prefix = 'QA_RUN_'.getenv('QA_RUN_ID').'_'.bin2hex(random_bytes(4));
        $staff = User::factory()->staff()->create(['name' => $prefix]);
        $a = Restaurant::factory()->for(User::factory()->admin()->create(['name' => $prefix.'_ownerA']), 'user')->create(['name' => $prefix.'_A']);
        $b = Restaurant::factory()->for(User::factory()->admin()->create(['name' => $prefix.'_ownerB']), 'user')->create(['name' => $prefix.'_B']);
        $staff->staffRestaurants()->attach($a->id);
        try {
            $staff->staffRestaurants()->attach($b->id);
            $this->fail('The database must reject unsupported multi-membership.');
        } catch (UniqueConstraintViolationException $error) {
            $this->assertSame('23000', $error->errorInfo[0]);
        }
        $this->assertSame([$a->id], $staff->fresh()->staffRestaurants()->pluck('restaurants.id')->all());
        $this->assertSame($a->id, $staff->fresh()->currentRestaurant()->id);
        $this->assertSame($a->id, $staff->fresh()->load('staffRestaurants')->currentRestaurant()->id);
        Sanctum::actingAs($staff);
        foreach ([$a, $b] as $tenant) {
            $response = $this->getJson('/api/auth/me?restaurant_id='.$tenant->id, ['Host' => $tenant->slug.'.localhost'])->assertOk();
            $this->assertSame($a->id, $response->json('user.restaurant.id'));
        }
    }
}
