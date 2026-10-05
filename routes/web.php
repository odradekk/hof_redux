<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:authentication');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:authentication');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store']);
    Route::view('/account', 'account.settings')->name('account');
    Route::post('/account/password', [AuthController::class, 'password'])->middleware('throttle:authentication')->name('account.password');
    Route::get('/', fn () => view('home', ['characters' => auth()->user()->characters()->with('equipment')->get()]))->middleware('character')->name('home');
});
foreach (['player', 'multiplayer', 'world'] as $module) {
    if (file_exists(__DIR__."/{$module}.php")) {
        require __DIR__."/{$module}.php";
    }
}
