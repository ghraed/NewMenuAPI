<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;



class DishController extends Controller
{
    public function index(Request $request)
    {
        $restaurant = Auth::user()->restaurant;

        return response()->json(
            $restaurant->dishes()
                ->with('assets')
                ->paginate(15)
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string|max:100',
            'image_url' => 'nullable|url',
        ]);

        $user = Auth::user();
        $restaurant = Restaurant::findOrFail(1);
        // !! delete the $dish = ... and replace it with the commented line below when auth user has restaurant relation
        // $dish = $user->restaurant->dishes()->create(
        // add uuid generation
        $validated['uuid'] = \Illuminate\Support\Str::uuid();
        $dish = $restaurant->dishes()->create(
            array_merge($validated, ['status' => 'draft'])
        );

        return response()->json($dish, 201);
    }

    public function show(Dish $dish)
    {
        $this->authorize('view', $dish);

        return response()->json($dish->load('assets'));
    }

    public function update(Request $request, Dish $dish)
    {
        $this->authorize('update', $dish);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'category' => 'sometimes|string|max:100',
            'image_url' => 'nullable|url',
        ]);

        $dish->update($validated);

        return response()->json($dish);
    }

    public function destroy(Dish $dish)
    {
        $this->authorize('delete', $dish);

        $dish->assets()->delete();
        $dish->delete();

        return response()->noContent();
    }

    public function publish(Dish $dish)
    {
        $this->authorize('update', $dish);

        if (
            !$dish->assets()->where('asset_type', 'glb')->exists() &&
            !$dish->assets()->where('asset_type', 'usdz')->exists()
        ) {
            return response()->json([
                'message' => 'Cannot publish dish without 3D assets'
            ], 422);
        }

        $dish->update(['status' => 'published']);

        return response()->json($dish);
    }

    public function unpublish(Dish $dish)
    {
        $this->authorize('update', $dish);

        $dish->update(['status' => 'draft']);

        return response()->json($dish);
    }
}
