<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemIngredientUsage extends Model
{
    protected $fillable = [
        'restaurant_id',
        'order_id',
        'order_item_id',
        'dish_id',
        'dish_ingredient_id',
        'ingredient_id',
        'ingredient_name_snapshot',
        'unit',
        'recipe_quantity_per_dish',
        'order_item_quantity',
        'consumed_quantity',
    ];

    protected $casts = [
        'recipe_quantity_per_dish' => 'decimal:3',
        'order_item_quantity' => 'integer',
        'consumed_quantity' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class)->withTrashed();
    }

    public function dishIngredient(): BelongsTo
    {
        return $this->belongsTo(DishIngredient::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
