<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CurrencySettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $restaurant = $this->getOwnedRestaurant($request);
        $hasOtherCurrencyColumn = Schema::hasColumn('restaurants', 'other_currency');

        return response()->json([
            'currency' => $restaurant->currency ?? 'USD',
            'other_currency' => $hasOtherCurrencyColumn ? ($restaurant->other_currency ?? null) : null,
            'dollar_rate' => $restaurant->dollar_rate ?? 1,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hasOtherCurrencyColumn = Schema::hasColumn('restaurants', 'other_currency');
        $validationRules = [
            'currency' => 'required|in:USD,LBP,SYP,SAR,AED,EUR,QAR',
            'dollar_rate' => 'required|numeric|gt:0',
        ];

        if ($hasOtherCurrencyColumn) {
            $validationRules['other_currency'] = 'required|in:USD,LBP,SYP,SAR,AED,EUR,QAR|different:currency';
        }

        $validated = $request->validate($validationRules);

        $restaurant = $this->getOwnedRestaurant($request);
        $payload = [
            'currency' => $validated['currency'],
            'dollar_rate' => (float) $validated['dollar_rate'],
        ];

        if ($hasOtherCurrencyColumn && isset($validated['other_currency'])) {
            $payload['other_currency'] = $validated['other_currency'];
        }

        $restaurant->update($payload);

        return response()->json([
            'message' => 'Currency settings updated successfully.',
            'currency' => $restaurant->currency,
            'other_currency' => $hasOtherCurrencyColumn ? ($restaurant->other_currency ?? null) : null,
            'dollar_rate' => $restaurant->dollar_rate,
        ]);
    }

    private function getOwnedRestaurant(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');
        $restaurant = $user->currentRestaurant();

        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }
}
