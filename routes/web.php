<?php

use App\Http\Controllers\AdminGameController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {
    Route::get('/', [GameController::class, 'index'])->name('dashboard');
    Route::post('/games/{game}/join', [GameController::class, 'join'])->name('games.join');

    Route::post('/games/{game}/add-players', [AdminGameController::class, 'addPlayers'])->name('games.add-players');
    Route::post('/admin/store-player', [AdminGameController::class, 'storePlayer'])->name('admin.store-player');
    Route::post('/games/{game}/store-guest', [AdminGameController::class, 'storeGuest'])->name('games.store-guest');

    Route::get('/games/{game}/draft', [DraftController::class, 'show'])->name('games.draft');
    Route::post('/games/{game}/pick', [DraftController::class, 'pick'])->name('games.pick');
});
