<?php

namespace App\Console\Commands;

use App\Models\DishAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateDishAssetsToProtectedStorage extends Command
{
    protected $signature = 'dish-assets:migrate-to-protected {--dry-run : Report the files that would move without changing them}';

    protected $description = 'Move legacy dish assets off the web-accessible public disk';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $migrated = 0;
        $failed = 0;

        DishAsset::query()
            ->where(function ($query): void {
                $query->whereNull('storage_disk')->orWhere('storage_disk', 'public');
            })
            ->orderBy('id')
            ->chunkById(100, function ($assets) use ($dryRun, &$migrated, &$failed): void {
                foreach ($assets as $asset) {
                    $path = ltrim((string) $asset->file_path, '/');

                    if ($path === '' || ! str_starts_with($path, 'dishes/')) {
                        $this->warn("Skipping asset {$asset->id}: unexpected file path.");
                        $failed++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Would migrate asset {$asset->id}: {$path}");
                        $migrated++;

                        continue;
                    }

                    if ($this->migrateAsset($asset, $path)) {
                        $migrated++;
                    } else {
                        $failed++;
                    }
                }
            });

        $verb = $dryRun ? 'would be migrated' : 'migrated';
        $this->info("Dish asset migration complete: {$migrated} {$verb}; {$failed} failed or skipped.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function migrateAsset(DishAsset $asset, string $path): bool
    {
        $public = Storage::disk('public');
        $protected = Storage::disk(DishAsset::PROTECTED_DISK);
        $createdDestination = false;

        try {
            if (! $protected->exists($path)) {
                if (! $public->exists($path)) {
                    $this->error("Asset {$asset->id} is missing from both public and protected storage.");

                    return false;
                }

                $stream = $public->readStream($path);
                if (! is_resource($stream)) {
                    $this->error("Could not read public asset {$asset->id}.");

                    return false;
                }

                try {
                    $createdDestination = (bool) $protected->writeStream($path, $stream);
                } finally {
                    fclose($stream);
                }

                if (! $createdDestination) {
                    $this->error("Could not write protected asset {$asset->id}.");

                    return false;
                }
            }

            $asset->forceFill(['storage_disk' => DishAsset::PROTECTED_DISK])->saveOrFail();
            $public->delete($path);

            return true;
        } catch (Throwable $exception) {
            if ($createdDestination) {
                $protected->delete($path);
            }

            $this->error("Could not migrate asset {$asset->id}: {$exception->getMessage()}");

            return false;
        }
    }
}
