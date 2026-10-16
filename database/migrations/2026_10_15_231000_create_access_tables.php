<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number')->unique();
            $table->string('hikcentral_id')->nullable()->unique();
            $table->string('name');
            $table->string('department')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate')->unique();
            $table->string('description');
            $table->timestamps();
        });
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('model');
            $table->string('base_url')->unique();
            $table->string('username');
            $table->text('password');
            $table->string('location');
            $table->string('direction')->default('unknown');
            $table->boolean('read_enabled')->default(false);
            $table->boolean('write_enabled')->default(false);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('rfid_tags', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();
            $table->timestamps();
        });
        Schema::create('rfid_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfid_tag_id')->constrained()->restrictOnDelete();
            // NULL after revocation permits history while enforcing one active owner per tag.
            $table->foreignId('active_tag_id')->nullable()->unique()->constrained('rfid_tags')->restrictOnDelete();
            $table->foreignId('person_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('provisionings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfid_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('operation');
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['rfid_assignment_id', 'device_id', 'operation']);
        });
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('external_id');
            $table->foreignId('rfid_assignment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('card_number', 32)->nullable()->index();
            $table->string('employee_number')->nullable();
            $table->string('event_type');
            $table->string('direction');
            $table->string('location');
            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at');
            $table->json('payload');
            $table->unique(['device_id', 'external_id']);
            $table->index(['person_id', 'occurred_at']);
            $table->index(['vehicle_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['movements', 'provisionings', 'rfid_assignments', 'rfid_tags', 'devices', 'vehicles', 'people'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
