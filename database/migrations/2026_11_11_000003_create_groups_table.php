<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->unsignedBigInteger('external_id');
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });

        Schema::table('people', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('id')->constrained('groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::dropIfExists('groups');
    }
};
