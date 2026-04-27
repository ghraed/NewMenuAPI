<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RoomPlan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RoomPlanService
{
    public function createPlan(Restaurant $restaurant, array $data): RoomPlan
    {
        return RoomPlan::query()->create([
            'restaurant_id' => $restaurant->id,
            'name' => trim((string) $data['name']),
            'width' => (int) $data['width'],
            'height' => (int) $data['height'],
            'background_image_path' => null,
        ]);
    }

    public function updatePlan(RoomPlan $roomPlan, array $data): RoomPlan
    {
        $roomPlan->update([
            'name' => trim((string) ($data['name'] ?? $roomPlan->name)),
            'width' => isset($data['width']) ? (int) $data['width'] : $roomPlan->width,
            'height' => isset($data['height']) ? (int) $data['height'] : $roomPlan->height,
        ]);

        return $roomPlan->fresh();
    }

    public function uploadBackgroundImage(RoomPlan $roomPlan, UploadedFile $file): RoomPlan
    {
        $oldPath = $roomPlan->background_image_path;
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $fileName = Str::uuid().'.'.$extension;
        $path = $file->storeAs("room-plans/{$roomPlan->id}", $fileName, 'public');

        $roomPlan->update([
            'background_image_path' => $path,
        ]);

        if ($oldPath) {
            try {
                Storage::disk('public')->delete($oldPath);
            } catch (\Throwable) {
                // Keep API success even if old file cleanup fails.
            }
        }

        return $roomPlan->fresh();
    }

    public function deletePlan(RoomPlan $roomPlan): void
    {
        DB::transaction(function () use ($roomPlan): void {
            $roomPlan->items()->get()->each(function ($item): void {
                $item->update(['is_active' => false]);
                $item->delete();
            });

            $roomPlan->delete();
        });
    }
}
