<?php

use App\Http\Controllers\Game\PlayerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'character'])->group(function () {
    Route::get('/characters', [PlayerController::class, 'roster'])->name('player.roster');
    Route::get('/characters/{id}', [PlayerController::class, 'character'])->whereNumber('id')->name('player.character');
    Route::get('/inventory', [PlayerController::class, 'inventory'])->name('player.inventory');
    Route::get('/shop', [PlayerController::class, 'shop'])->name('player.shop');
    Route::get('/shop/sell', [PlayerController::class, 'shop'])->name('player.shop.sell');
    Route::get('/shop/work', [PlayerController::class, 'shop'])->name('player.shop.work');
    Route::get('/smithy/refine', [PlayerController::class, 'crafting'])->name('player.smithy.refine');
    Route::get('/smithy/create', [PlayerController::class, 'crafting'])->name('player.smithy.create');
    Route::redirect('/crafting', '/smithy/refine', 302)->name('player.crafting');
    Route::redirect('/preferences', '/account', 302)->name('player.preferences');
    Route::post('/player/{command}', [PlayerController::class, 'command'])->name('player.command');
});
