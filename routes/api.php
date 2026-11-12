<?php

use App\Http\Controllers\InterfaceHController;
use App\Http\Middleware\EnsureInterfaceHToken;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureInterfaceHToken::class, 'throttle:120,1'])->group(function () {
    Route::post('/connection', [InterfaceHController::class, 'connection']);
    Route::post('/{resource}', [InterfaceHController::class, 'receive'])
        ->whereIn('resource', ['devices', 'doors', 'groups', 'personnel', 'events']);
});
