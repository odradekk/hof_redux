<?php

use App\Http\Controllers\Game\MultiplayerController;
use Illuminate\Support\Facades\Route;

Route::get('/ranking', [MultiplayerController::class, 'ranking'])->name('ranking');
Route::middleware(['auth', 'character'])->group(function () {
    Route::get('/auction', [MultiplayerController::class, 'auction'])->name('auction');
    Route::post('/auction/member', [MultiplayerController::class, 'join'])->name('auction.join');
    Route::post('/auction/list', [MultiplayerController::class, 'exhibit'])->name('auction.exhibit');
    Route::post('/auction/{auction}/bid', [MultiplayerController::class, 'bid'])->name('auction.bid');
    Route::post('/ranking/team', [MultiplayerController::class, 'rankTeam'])->name('ranking.team');
    Route::post('/ranking/challenge', [MultiplayerController::class, 'rankChallenge'])->name('ranking.challenge');
    Route::get('/bosses', [MultiplayerController::class, 'bosses'])->name('bosses');
    Route::get('/bosses/{boss}', [MultiplayerController::class, 'bosses'])->whereNumber('boss')->name('bosses.show');
    Route::post('/bosses/{boss}/challenge', [MultiplayerController::class, 'bossChallenge'])->name('bosses.challenge');
    Route::get('/multiplayer/reports/{kind}/{report}', [MultiplayerController::class, 'report'])->whereIn('kind', ['ranking', 'boss'])->name('multiplayer.report');
});
