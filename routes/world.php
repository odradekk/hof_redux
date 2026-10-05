<?php

use App\Http\Controllers\Game\AccountDeletionController;
use App\Http\Controllers\Game\AdminController;
use App\Http\Controllers\Game\CommunityController;
use App\Http\Controllers\Game\InformationController;
use App\Http\Controllers\Game\ReportController;
use App\Http\Controllers\Game\WorldController;
use Illuminate\Support\Facades\Route;

Route::get('/manual/{section?}', [InformationController::class, 'manual'])->name('manual');
Route::get('/updates', [InformationController::class, 'updates'])->name('updates');
Route::get('/catalog/{kind?}', [InformationController::class, 'catalog'])->name('catalog');
Route::get('/catalog/{kind}/{id}', [InformationController::class, 'entry'])->where('id', '[0-9]+')->name('catalog.entry');
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
Route::get('/reports/boss/{challenge}', [ReportController::class, 'boss'])->name('reports.boss');
Route::get('/reports/ranking/{challenge}', [ReportController::class, 'ranking'])->name('reports.ranking');
Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
Route::middleware(['auth', 'character'])->group(function () {
    Route::get('/hunt', [WorldController::class, 'index'])->name('hunt');
    Route::get('/hunt/{area}', [WorldController::class, 'area'])->name('hunt.area');
    Route::post('/hunt/{area}', [WorldController::class, 'hunt']);
    Route::post('/characters/{character}/simulation', [WorldController::class, 'characterSimulation'])->whereNumber('character')->name('character.simulation');
    Route::get('/simulation', [WorldController::class, 'simulation'])->name('simulation');
    Route::post('/simulation', [WorldController::class, 'simulate']);
    Route::get('/town', [CommunityController::class, 'town'])->name('town');
    Route::post('/town/messages', [CommunityController::class, 'post'])->middleware('throttle:20,1')->name('town.post');
});
Route::middleware('auth')->group(function () {
    Route::post('/account/delete', [AccountDeletionController::class, 'delete'])->middleware('throttle:authentication')->name('account.delete');
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/users/{user}', [AdminController::class, 'user'])->name('user');
        Route::post('/users/{user}', [AdminController::class, 'updateUser'])->name('user.update');
        Route::post('/users/{user}/delete', [AdminController::class, 'deleteUser'])->name('user.delete');
        Route::post('/announcements', [AdminController::class, 'announcement'])->name('announcement');
        Route::post('/moderate', [AdminController::class, 'moderate'])->name('moderate');
        Route::post('/reports', [AdminController::class, 'reports'])->name('reports');
        Route::post('/maintenance', [AdminController::class, 'maintenance'])->name('maintenance');
    });
});
