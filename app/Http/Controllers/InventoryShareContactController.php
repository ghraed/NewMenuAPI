<?php

namespace App\Http\Controllers;

use App\Models\InventoryShareContact;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryShareContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);

        return response()->json([
            'contacts' => InventoryShareContact::query()
                ->where('restaurant_id', $restaurant->id)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'phone', 'created_at', 'updated_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $validated = $this->validateContact($request);
        $phone = trim($validated['phone']);

        $contact = InventoryShareContact::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => trim($validated['name']),
            'phone' => $phone,
        ]);

        return response()->json([
            'message' => 'Contact created successfully.',
            'contact' => $contact->only(['id', 'name', 'phone', 'created_at', 'updated_at']),
        ], 201);
    }

    public function update(Request $request, InventoryShareContact $shareContact): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $shareContact = $this->assertBelongsToRestaurant($shareContact, $restaurant);
        $validated = $this->validateContact($request);

        $shareContact->update([
            'name' => trim($validated['name']),
            'phone' => trim($validated['phone']),
        ]);

        return response()->json([
            'message' => 'Contact updated successfully.',
            'contact' => $shareContact->fresh()->only(['id', 'name', 'phone', 'created_at', 'updated_at']),
        ]);
    }

    public function destroy(Request $request, InventoryShareContact $shareContact): JsonResponse
    {
        $restaurant = $this->getRestaurantForRequest($request);
        $shareContact = $this->assertBelongsToRestaurant($shareContact, $restaurant);
        $shareContact->delete();

        return response()->json(['message' => 'Contact removed successfully.']);
    }

    private function getRestaurantForRequest(Request $request): Restaurant
    {
        $user = $request->user();
        $user->loadMissing('restaurant', 'staffRestaurants');

        $restaurant = $user->currentRestaurant();
        if (! $restaurant) {
            abort(403, 'No restaurant is linked to this account');
        }

        return $restaurant;
    }

    /**
     * @return array{name:string,phone:string}
     */
    private function validateContact(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[+0-9().\-\s]+$/'],
        ]);

        $digitCount = strlen(preg_replace('/\D+/', '', trim($validated['phone'])) ?? '');
        if ($digitCount < 7 || $digitCount > 15) {
            throw ValidationException::withMessages([
                'phone' => ['The phone number must contain between 7 and 15 digits.'],
            ]);
        }

        return $validated;
    }

    private function assertBelongsToRestaurant(
        InventoryShareContact $shareContact,
        Restaurant $restaurant
    ): InventoryShareContact {
        if ((int) $shareContact->restaurant_id !== (int) $restaurant->id) {
            abort(404);
        }

        return $shareContact;
    }
}
