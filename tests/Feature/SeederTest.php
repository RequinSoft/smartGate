<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_seeder_is_repeatable_and_grants_all_expected_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 10);
        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('users', 0);
        $this->assertCount(10, Role::findByName('Administrador')->permissions);
        $this->assertCount(3, Role::findByName('Auditor')->permissions);
    }
}
