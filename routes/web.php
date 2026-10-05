<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Game\PlayerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [HomeController::class, 'landing'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:authentication');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:authentication');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store']);
    Route::get('/account', [PlayerController::class, 'preferences'])->name('account');
    Route::post('/account/password', [AuthController::class, 'password'])->middleware('throttle:authentication')->name('account.password');
    Route::get('/', [HomeController::class, 'index'])->middleware('character')->name('home');
});
foreach (['player', 'multiplayer', 'world'] as $module) {
    if (file_exists(__DIR__."/{$module}.php")) {
        require __DIR__."/{$module}.php";
    }
}

if (app()->environment(['local', 'testing'])) {
    Route::get('/dev/ui', StyleguideController::class)->name('dev.ui');
}
