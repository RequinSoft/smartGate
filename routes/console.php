<?php

use App\Jobs\PollDevice;
use App\Jobs\RunBackup;
use App\Models\Device;
use App\Models\User;
use App\Services\ImportPeople;
use App\Support\Audit;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;

Artisan::command('smartgate:admin {email} {--name=Administrador}', function () {
    $password = $this->secret('Contraseña del administrador (mínimo 12 caracteres)');
    Validator::make(['email' => $this->argument('email'), 'password' => $password], [
        'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:12|max:128',
    ])->validate();
    DB::transaction(function () use ($password) {
        (new RoleSeeder)->run();
        $user = User::create(['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password]);
        $user->assignRole('Administrador');
        Audit::record('admin.created', $user);
    });
    $this->info('Administrador creado sin contraseña predeterminada.');
})->purpose('Crear un administrador mediante contraseña interactiva');

Artisan::command('smartgate:import-people', function (ImportPeople $import) {
    $this->info('Personal importado: '.$import->run());
})->purpose('Importar personal de HikCentral; los errores detienen la importación');

Schedule::call(function () {
    Device::where('read_enabled', true)->each(fn (Device $device) => PollDevice::dispatch($device->id));
})->name('smartgate-poll')->everyMinute()->withoutOverlapping();
Schedule::job(new RunBackup)->dailyAt('02:00');
Schedule::command('backup:clean')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('04:00')->withoutOverlapping();
