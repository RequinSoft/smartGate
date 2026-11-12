<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_records', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('resource', 24);
            $table->string('external_id', 64);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['source', 'resource', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_records');
    }
};
