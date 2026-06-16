<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoIngredientAlignmentSeeder extends Seeder
{
    public function run(): void
    {
        $sqlPath = $this->resolveSqlPath();

        if ($sqlPath === null) {
            $this->command?->error("Demo ingredient SQL not found at: {$sqlPath}");
            return;
        }

        $sql = file_get_contents($sqlPath);
        if ($sql === false || trim($sql) === '') {
            $this->command?->error("Demo ingredient SQL is empty or unreadable: {$sqlPath}");
            return;
        }

        DB::unprepared($sql);

        $this->command?->info('DemoIngredientAlignmentSeeder applied successfully.');
    }

    private function resolveSqlPath(): ?string
    {
        $configured = env('DEMO_INGREDIENT_SQL_PATH');
        $candidates = array_filter([
            is_string($configured) && trim($configured) !== '' ? trim($configured) : null,
            dirname(base_path()) . '/Menu_React/backups/demo_ingredient_fix.sql',
            dirname(base_path()) . '/NewMenuReact/backups/demo_ingredient_fix.sql',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
