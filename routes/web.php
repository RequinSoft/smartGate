<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VehiclePhotoController;
use App\Livewire\Workspace;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/reports/movements.csv', ReportController::class)->name('reports.movements');
    Route::get('/vehicles/{vehicle}/photo', VehiclePhotoController::class)->name('vehicles.photo');
    foreach (Workspace::SECTIONS as $section => $definition) {
        Route::get('/'.$section, Workspace::class)->defaults('section', $section)->name($section);
    }
});
