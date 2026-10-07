<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Http\Exceptions\HttpResponseException;

class RestaurantCommercePolicy
{
    public function acceptsNewCommerce(Restaurant $restaurant): bool
    {
        // Check current persisted status, including requests using pre-suspension tokens
        // or a restaurant relationship loaded earlier in the request.
        return Restaurant::query()->whereKey($restaurant->id)->value('status') === 'active';
    }

    public function assertNewCommerce(Restaurant $restaurant): void
    {
        if (! $this->acceptsNewCommerce($restaurant)) {
            $this->reject();
        }
    }

    /** @param array<int, array{dish_id:int, quantity:int}> $items */
    public function assertPendingOrderEdit(Restaurant $restaurant, Order $order, array $items): void
    {
        if ($this->acceptsNewCommerce($restaurant)) {
            return;
        }

        // Finishing/correcting existing work is allowed; adding food is new commerce.
        $quantities = $order->items()->pluck('quantity', 'dish_id');
        foreach ($items as $item) {
            if ((int) $item['quantity'] > (int) $quantities->get($item['dish_id'], 0)) {
                $this->reject();
            }
        }
    }

    private function reject(): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'restaurant_inactive',
            'message' => __('messages.restaurant.inactive_commerce'),
        ], 403));
    }
}
