<?php

namespace App\Services;

use App\Models\Dish;
use App\Models\DishAsset;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DishAssetReplacementService
{
    /**
     * Stage files before replacing records, and track each destination before
     * writing so even partially written files can be cleaned on failure.
     *
     * @param  array<int, string>  $types
     * @param  Closure(Closure): array<int, array<string, mixed>>  $stage
     * @return Collection<int, DishAsset>
     */
    public function replace(Dish $dish, array $types, Closure $stage): Collection
    {
        $stagedFiles = [];

        try {
            $replacements = $stage(function (string $disk, string $path) use (&$stagedFiles): void {
                $stagedFiles[] = ['disk' => $disk, 'path' => $path];
            });

            [$assets, $previous] = DB::transaction(function () use ($dish, $types, $replacements): array {
                // Serialize replacements for the same dish so the old-record
                // snapshot is current even when uploads overlap.
                Dish::query()->whereKey($dish->id)->lockForUpdate()->firstOrFail();
                $previous = $dish->assets()->whereIn('asset_type', $types)->lockForUpdate()->get();
                $assets = new Collection;

                foreach ($replacements as $attributes) {
                    $asset = $dish->assets()->create([
                        'uuid' => (string) Str::uuid(),
                        ...$attributes,
                        'file_url' => '',
                    ]);
                    $asset->update(['file_url' => route('api.assets.show', ['asset' => $asset->id], false)]);
                    $assets->push($asset);
                }

                foreach ($previous as $asset) {
                    $asset->delete();
                }

                return [$assets, $previous];
            });
        } catch (Throwable $exception) {
            foreach ($stagedFiles as $file) {
                $this->deleteUnreferencedFile($file['disk'], $file['path']);
            }

            throw $exception;
        }

        // Existing files remain intact until every replacement record has
        // been saved and the replacement transaction has succeeded.
        foreach ($previous as $asset) {
            if ($asset->file_path) {
                $this->deleteUnreferencedFile($asset->storage_disk ?: 'public', $asset->file_path);
            }
        }

        return $assets;
    }

    private function deleteUnreferencedFile(string $disk, string $path): void
    {
        try {
            $stillReferenced = DishAsset::query()->where('file_path', $path)->get(['storage_disk'])
                ->contains(fn (DishAsset $asset): bool => ($asset->storage_disk ?: 'public') === $disk);

            if (! $stillReferenced && ! Storage::disk($disk)->delete($path)) {
                Log::warning('Failed to clean up a replaced dish asset file.', ['disk' => $disk, 'path' => $path]);
            }
        } catch (Throwable $exception) {
            // Cleanup failure must not turn a completed replacement into an
            // error response or hide the original save/storage exception.
            Log::warning('Failed to clean up a replaced dish asset file.', [
                'disk' => $disk, 'path' => $path, 'exception' => $exception->getMessage(),
            ]);
        }
    }
}
