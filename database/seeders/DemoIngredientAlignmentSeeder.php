<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoIngredientAlignmentSeeder extends Seeder
{
    public function run(): void
    {
        $sqlPath = dirname(base_path()) . '/Menu_React/backups/demo_ingredient_fix.sql';

        if (! is_file($sqlPath)) {
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
}
