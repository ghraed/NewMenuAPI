<?php

namespace App\Console\Commands;

use App\Models\DishAsset;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
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
        $temporaryPath = null;
        $databaseSwitched = false;
        $sourceFingerprint = null;

        try {
            if (! $public->exists($path)) {
                $this->error("Asset {$asset->id} is missing from public storage; the protected copy cannot be verified.");

                return false;
            }

            $sourceFingerprint = $this->fingerprint($public, $path);
            $destinationFingerprint = $protected->exists($path)
                ? $this->fingerprint($protected, $path)
                : null;

            if (! $this->fingerprintsMatch($sourceFingerprint, $destinationFingerprint)) {
                $temporaryPath = $path.'.migration-'.Str::uuid().'.tmp';
                $this->copyToProtectedTemporaryPath($public, $protected, $path, $temporaryPath);

                $temporaryFingerprint = $this->fingerprint($protected, $temporaryPath);
                if (! $this->fingerprintsMatch($sourceFingerprint, $temporaryFingerprint)) {
                    throw new RuntimeException('Protected temporary copy failed byte-integrity verification.');
                }

                if ($protected->exists($path) && (! $protected->delete($path) || $protected->exists($path))) {
                    throw new RuntimeException('Could not remove the mismatched protected destination.');
                }

                if (! $protected->move($temporaryPath, $path)) {
                    throw new RuntimeException('Could not promote the verified protected temporary copy.');
                }
                $temporaryPath = null;
            }

            if (! $this->fingerprintsMatch($sourceFingerprint, $this->fingerprint($protected, $path))) {
                throw new RuntimeException('Protected destination failed final byte-integrity verification.');
            }

            $asset->forceFill(['storage_disk' => DishAsset::PROTECTED_DISK])->saveOrFail();
            $databaseSwitched = true;

            $deleted = $public->delete($path);
            $sourceStillExists = $public->exists($path);
            if ($sourceStillExists) {
                $reason = $deleted ? 'despite a successful delete result' : 'after deletion failed';
                throw new RuntimeException("Public source still exists {$reason}.");
            }

            return true;
        } catch (Throwable $exception) {
            if ($temporaryPath !== null && $protected->exists($temporaryPath)) {
                $protected->delete($temporaryPath);
            }

            if ($databaseSwitched) {
                try {
                    // Some adapters can report a delete failure after completing it.
                    // If the public object is gone, the verified protected state is final.
                    if (! $public->exists($path)) {
                        return true;
                    }

                    // Keep the verified destination, but make this row selectable on
                    // the next retry while the public exposure still exists.
                    $asset->forceFill(['storage_disk' => 'public'])->saveOrFail();
                } catch (Throwable $rollbackException) {
                    $this->error("Could not restore retry state for asset {$asset->id}: {$rollbackException->getMessage()}");
                }
            }

            $this->error("Could not migrate asset {$asset->id}: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * @return array{bytes:int,sha256:string}
     */
    private function fingerprint(FilesystemAdapter $disk, string $path): array
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read asset bytes at {$path}.");
        }

        try {
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);

            return [
                'bytes' => $bytes,
                'sha256' => hash_final($hash),
            ];
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array{bytes:int,sha256:string}|null  $left
     * @param  array{bytes:int,sha256:string}|null  $right
     */
    private function fingerprintsMatch(?array $left, ?array $right): bool
    {
        return $left !== null
            && $right !== null
            && $left['bytes'] === $right['bytes']
            && hash_equals($left['sha256'], $right['sha256']);
    }

    private function copyToProtectedTemporaryPath(
        FilesystemAdapter $public,
        FilesystemAdapter $protected,
        string $sourcePath,
        string $temporaryPath
    ): void {
        $stream = $public->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not read the public source asset.');
        }

        try {
            $written = $protected->writeStream($temporaryPath, $stream);
        } finally {
            fclose($stream);
        }

        if (! $written) {
            throw new RuntimeException('Could not write the protected temporary copy.');
        }
    }
}
