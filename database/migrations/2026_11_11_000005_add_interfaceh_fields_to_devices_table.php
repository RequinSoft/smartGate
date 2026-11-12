<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('source', 64)->nullable()->after('id');
            $table->unsignedBigInteger('external_id')->nullable()->after('source');
            $table->string('ip_address', 45)->nullable()->after('base_url');
            $table->unsignedInteger('port')->nullable()->after('ip_address');
            $table->string('serial', 128)->nullable()->after('port');
            $table->string('type', 64)->nullable()->after('serial');
            $table->boolean('online')->nullable()->after('type');
            $table->string('base_url')->nullable()->change();
            $table->string('username')->nullable()->change();
            $table->text('password')->nullable()->change();
            $table->string('location')->default('')->change();
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropColumn(['source', 'external_id', 'ip_address', 'port', 'serial', 'type', 'online']);
        });
    }
};
