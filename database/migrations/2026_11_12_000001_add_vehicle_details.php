<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('version', 100)->nullable();
            $table->string('color', 64)->nullable();
            $table->string('photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['brand', 'model', 'version', 'color', 'photo_path']);
        });
    }
};
