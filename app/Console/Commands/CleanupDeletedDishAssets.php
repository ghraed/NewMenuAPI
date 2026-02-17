<?php

namespace App\Console\Commands;

use App\Models\Dish;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupDeletedDishAssets extends Command
{
    protected $signature = 'dishes:cleanup-deleted-assets';

    protected $description = 'Delete model files for dishes that have stayed deleted for at least 7 days';

    public function handle(): int
    {
        $threshold = now()->subDays(7);
        $dishes = Dish::onlyTrashed()
            ->where('deleted_at', '<=', $threshold)
            ->with('assets')
            ->get();

        $deletedFiles = 0;
        $deletedAssets = 0;

        foreach ($dishes as $dish) {
            foreach ($dish->assets as $asset) {
                if ($asset->file_path) {
                    Storage::disk('public')->delete($asset->file_path);
                    $deletedFiles++;
                }

                $asset->delete();
                $deletedAssets++;
            }
        }

        $this->info("Cleanup completed: {$deletedFiles} files removed, {$deletedAssets} asset records deleted.");

        return self::SUCCESS;
    }
}
