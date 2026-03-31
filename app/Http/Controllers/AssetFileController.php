<?php

namespace App\Http\Controllers;

use App\Models\DishAsset;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetFileController extends Controller
{
    public function show(DishAsset $asset): StreamedResponse|BinaryFileResponse|RedirectResponse
    {
        $diskName = $asset->storage_disk ?: 'public';
        $path = $asset->file_path;

        if (! $path) {
            throw new HttpResponseException(response()->json([
                'message' => 'Asset file not found.',
            ], 404));
        }

        if ($diskName === 'public') {
            if (! Storage::disk('public')->exists($path)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Asset file not found.',
                ], 404));
            }

            return Storage::disk('public')->response($path, null, $this->headersFor($asset));
        }

        $disk = Storage::disk($diskName);

        if (method_exists($disk, 'temporaryUrl')) {
            return redirect()->away($disk->temporaryUrl($path, now()->addMinutes(15)));
        }

        if (! $disk->exists($path)) {
            throw new HttpResponseException(response()->json([
                'message' => 'Asset file not found.',
            ], 404));
        }

        return $disk->response($path, null, $this->headersFor($asset));
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(DishAsset $asset): array
    {
        return array_filter([
            'Content-Type' => $asset->mime_type,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
