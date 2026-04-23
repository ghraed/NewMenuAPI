<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencySettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);

        return response()->json([
            'currency' => $restaurant->currency ?? 'USD',
            'dollar_rate' => $restaurant->dollar_rate ?? 1,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => 'required|in:USD,LBP,SYP',
            'dollar_rate' => 'required|numeric|gt:0',
        ]);

        $restaurant = $this->getOwnedRestaurant($request);

        $restaurant->update([
            'currency' => $validated['currency'],
            'dollar_rate' => $validated['currency'] === 'USD'
                ? 1
                : (float) $validated['dollar_rate'],
        ]);

        return response()->json([
            'message' => 'Currency settings updated successfully.',
            'currency' => $restaurant->currency,
            'dollar_rate' => $restaurant->dollar_rate,
        ]);
    }

    private function getOwnedRestaurant(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant');

        if (! $user->restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $user->restaurant;
    }
}
