<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    public function updateName(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $user->loadMissing('restaurant');

        if (!$user->restaurant) {
            return response()->json([
                'message' => 'No restaurant is linked to this account',
            ], 403);
        }

        $user->restaurant->update([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Restaurant name updated successfully.',
            'restaurant' => [
                'id' => $user->restaurant->id,
                'name' => $user->restaurant->name,
                'slug' => $user->restaurant->slug,
            ],
        ]);
    }
}
