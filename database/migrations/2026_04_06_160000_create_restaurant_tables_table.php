<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->timestamps();

            $table->unique(['restaurant_id', 'name']);
        });

        $restaurantIds = DB::table('restaurants')
            ->pluck('id');

        $now = now();
        $rows = [];

        foreach ($restaurantIds as $restaurantId) {
            foreach (range(1, 10) as $number) {
                $rows[] = [
                    'restaurant_id' => $restaurantId,
                    'name' => sprintf('T%02d', $number),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('restaurant_tables')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
