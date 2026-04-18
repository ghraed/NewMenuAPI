<?php

namespace Tests\Feature;

use App\Models\GlobalIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalIngredientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_list_global_ingredients(): void
    {
        GlobalIngredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'fresh mint leaves',
            'name_ar' => 'أوراق نعناع طازجة',
            'normalized_name' => 'fresh mint leaves',
        ]);

        GlobalIngredient::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'extra virgin olive oil',
            'name_ar' => 'زيت زيتون بكر ممتاز',
            'normalized_name' => 'extra virgin olive oil',
        ]);

        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($user);

        $response = $this->get('/api/global-ingredients');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.normalized_name', 'extra virgin olive oil')
            ->assertJsonPath('1.normalized_name', 'fresh mint leaves');
    }

    public function test_staff_user_cannot_list_global_ingredients(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STAFF]);
        Sanctum::actingAs($user);

        $response = $this->get('/api/global-ingredients');

        $response->assertForbidden();
    }
}
