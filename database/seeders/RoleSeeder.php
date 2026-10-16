<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissions = ['people.manage', 'vehicles.manage', 'rfid.manage', 'devices.manage', 'integrations.manage', 'movements.view', 'reports.export', 'audit.view', 'users.manage', 'backups.manage'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach ([
            'Administrador' => $permissions,
            'Operador' => ['people.manage', 'vehicles.manage', 'rfid.manage', 'movements.view'],
            'Auditor' => ['movements.view', 'reports.export', 'audit.view'],
        ] as $name => $grants) {
            Role::findOrCreate($name, 'web')->syncPermissions($grants);
        }
    }
}
