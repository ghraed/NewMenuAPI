<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('global_ingredients', function (Blueprint $table) {
            $table->string('storage_disk')->default('public')->after('normalized_name');
            $table->string('file_path')->nullable()->after('storage_disk');
            $table->string('source_file_name')->nullable()->after('file_path');
            $table->bigInteger('file_size')->nullable()->after('source_file_name');
            $table->string('mime_type', 100)->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('global_ingredients', function (Blueprint $table) {
            $table->dropColumn(['mime_type', 'file_size', 'source_file_name', 'file_path', 'storage_disk']);
        });
    }
};
