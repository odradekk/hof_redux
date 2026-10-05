<?php

use App\Http\Controllers\Game\PlayerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'character'])->group(function () {
    Route::get('/characters', [PlayerController::class, 'roster'])->name('player.roster');
    Route::get('/characters/{id}', [PlayerController::class, 'character'])->whereNumber('id')->name('player.character');
    Route::get('/inventory', [PlayerController::class, 'inventory'])->name('player.inventory');
    Route::get('/shop', [PlayerController::class, 'shop'])->name('player.shop');
    Route::get('/crafting', [PlayerController::class, 'crafting'])->name('player.crafting');
    Route::get('/preferences', [PlayerController::class, 'preferences'])->name('player.preferences');
    Route::post('/player/{command}', [PlayerController::class, 'command'])->name('player.command');
});
