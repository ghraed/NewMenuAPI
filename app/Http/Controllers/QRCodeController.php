<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

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
}
