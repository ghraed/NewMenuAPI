<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Feature;
use App\Models\Restaurant;
use App\Models\RestaurantFeature;
use App\Services\DeepSeekChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatMenuAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_availability_question_lists_all_matching_pizzas_and_gently_highlights_preferred_one(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'pizza-chat-menu']);
        $this->enableChatbot($restaurant);

        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Margherita Pizza',
            'category' => 'Classic Pizza',
            'description' => 'Tomato, mozzarella, and fresh basil on a crisp crust.',
            'price' => 11.5,
            'is_profitable' => false,
        ]);
        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'Four Cheese Pizza',
            'category' => 'Classic Pizza',
            'description' => 'A gooey blend of four cheeses with a golden finish.',
            'price' => 13,
            'is_profitable' => false,
        ]);
        Dish::factory()->for($restaurant)->published()->create([
            'name' => 'BBQ Chicken Pizza',
            'category' => 'Specialty Pizza',
            'description' => 'Smoky barbecue chicken with melted mozzarella and red onion.',
            'price' => 15,
            'is_profitable' => true,
        ]);

        $chatService = Mockery::mock(DeepSeekChatService::class);
        $chatService->shouldNotReceive('chat');
        $this->app->instance(DeepSeekChatService::class, $chatService);

        $response = $this->postJson('/api/chat', [
            'message' => 'What pizza do you have?',
            'restaurant_slug' => $restaurant->slug,
            'language' => 'en',
        ]);

        $response->assertOk();
        $reply = (string) $response->json('reply');

        $this->assertStringContainsString('Margherita Pizza', $reply);
        $this->assertStringContainsString('Four Cheese Pizza', $reply);
        $this->assertStringContainsString('BBQ Chicken Pizza', $reply);
        $this->assertStringContainsString('Smoky barbecue chicken', $reply);
        $this->assertStringNotContainsString('Tomato, mozzarella, and fresh basil', $reply);
        $this->assertStringNotContainsString('gooey blend of four cheeses', $reply);
        $this->assertStringContainsString('lovely choice', $reply);
        $this->assertDoesNotMatchRegularExpression('/profit|priority|margin|internal ranking/i', $reply);
    }

    private function enableChatbot(Restaurant $restaurant): void
    {
        $feature = Feature::query()->create([
            'key' => 'ai_chatbot',
            'name' => 'AI Chatbot',
            'description' => 'Test feature',
            'category' => 'Tests',
            'is_active_by_default' => false,
        ]);

        RestaurantFeature::query()->create([
            'restaurant_id' => $restaurant->id,
            'feature_id' => $feature->id,
            'enabled' => true,
        ]);
    }
}
