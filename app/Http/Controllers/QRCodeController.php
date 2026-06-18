<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\RestaurantTable;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QRCodeController extends Controller
{
    public function generate(Dish $dish)
    {
        $this->authorize('view', $dish);

        // Check if QR already exists
        $qrCode = $dish->qrCode;
        if (!$qrCode) {
            $url = route('guest.show-dish', [
                'restaurant_slug' => $dish->restaurant->slug,
                'dish_id' => $dish->id,
            ]);

            $qrCode = $dish->qrCode()->create([
                'code_url' => $url,
            ]);
        }

        return response()->json([
            'url' => $qrCode->code_url,
            'qr_image' => $this->generateQRImage($qrCode->code_url),
        ]);
    }

    public function download(Dish $dish)
    {
        $this->authorize('view', $dish);

        $qrCode = $dish->qrCode;
        if (!$qrCode) {
            $this->generate($dish);
            $qrCode = $dish->qrCode;
        }

        $qrImage = $this->generateQRImage($qrCode->code_url);
        $binary = base64_decode(explode(',', $qrImage)[1]);

        return response()
            ->streamDownload(function () use ($binary) {
                echo $binary;
            }, "dish_{$dish->id}_qr.png");
    }

    private function generateQRImage(string $url): string
    {
        $qr = new QrCode($url);
        $writer = new PngWriter();
        $result = $writer->write($qr);

        return 'data:image/png;base64,' . base64_encode($result->getString());
    }

    public function generateTable(Request $request, RestaurantTable $restaurantTable): JsonResponse
    {
        $this->authorizeTableAccess($request, $restaurantTable);
        $url = $this->buildTableUrl($restaurantTable);

        return response()->json([
            'url' => $url,
            'qr_image' => $this->generateQRImage($url),
        ]);
    }

    public function downloadTable(Request $request, RestaurantTable $restaurantTable)
    {
        $this->authorizeTableAccess($request, $restaurantTable);
        $url = $this->buildTableUrl($restaurantTable);
        $qrImage = $this->generateQRImage($url);
        $binary = base64_decode(explode(',', $qrImage)[1]);

        return response()
            ->streamDownload(function () use ($binary) {
                echo $binary;
            }, "table_{$restaurantTable->id}_qr.png");
    }

    private function authorizeTableAccess(Request $request, RestaurantTable $restaurantTable): void
    {
        $restaurant = $request->user()?->currentRestaurant();

        if (! $restaurant || $restaurantTable->restaurant_id !== $restaurant->id) {
            abort(404);
        }
    }

    private function buildTableUrl(RestaurantTable $restaurantTable): string
    {
        $tableNumber = $this->resolveRestaurantScopedTableNumber($restaurantTable);
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $frontendUrl . "/menu/table/{$tableNumber}";
    }

    private function resolveRestaurantScopedTableNumber(RestaurantTable $restaurantTable): int
    {
        $tables = RestaurantTable::query()
            ->where('restaurant_id', $restaurantTable->restaurant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id']);

        $position = $tables->values()->search(fn (RestaurantTable $candidate) => $candidate->id === $restaurantTable->id);

        if ($position === false) {
            return 1;
        }

        return $position + 1;
    }
}
