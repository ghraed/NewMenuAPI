<?php

// The QA bootstrap rejects any non-disposable database before this script writes.
require __DIR__.'/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Schema::create('dishes', function (Blueprint $table): void {
    $table->id();
    $table->unsignedInteger('calories')->nullable();
});

DB::table('dishes')->insert([
    ['id' => 1, 'calories' => null],
    ['id' => 2, 'calories' => 0],
    ['id' => 3, 'calories' => 245],
]);

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_09_000001_add_dish_nutrition.php';
$migration->up();

$columns = DB::select(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dishes'
       AND COLUMN_NAME IN ('calories', 'protein', 'carbs', 'fat')"
);
$actual = [];
foreach ($columns as $column) {
    $actual[$column->COLUMN_NAME] = [$column->COLUMN_TYPE, $column->IS_NULLABLE];
}
foreach (['calories', 'protein', 'carbs', 'fat'] as $field) {
    if (($actual[$field] ?? null) !== ['decimal(10,3)', 'YES']) {
        throw new RuntimeException("Unexpected migrated {$field} column.");
    }
}

$rows = DB::table('dishes')->orderBy('id')->get()->map(fn ($row): array => [
    (int) $row->id,
    $row->calories === null ? null : (string) $row->calories,
    $row->protein,
    $row->carbs,
    $row->fat,
])->all();
if ($rows !== [
    [1, null, null, null, null],
    [2, '0.000', null, null, null],
    [3, '245.000', null, null, null],
]) {
    throw new RuntimeException('Existing dish nutrition values changed during migration.');
}

Schema::drop('dishes');
echo "Existing dish calories preserved by nutrition migration.\n";
