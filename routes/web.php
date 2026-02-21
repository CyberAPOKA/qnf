<?php

use App\Http\Controllers\AdminGameController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\GameController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {
    Route::get('/', [GameController::class, 'index'])->name('dashboard');
    Route::post('/games/{game}/join', [GameController::class, 'join'])->name('games.join');

    Route::post('/games/{game}/add-players', [AdminGameController::class, 'addPlayers'])
        ->name('games.add-players');
    Route::post('/games/{game}/draw-captains', [AdminGameController::class, 'drawCaptains'])
        ->name('games.draw-captains');

    Route::get('/games/{game}/draft', [DraftController::class, 'show'])->name('games.draft');
    Route::post('/games/{game}/pick', [DraftController::class, 'pick'])->name('games.pick');
});
