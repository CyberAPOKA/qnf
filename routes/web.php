<?php

use App\Enums\Position;
use App\Http\Controllers\AdminGameController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\AdminPlayerController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {

    Route::put('/profile/position', function (Request $request) {
        abort_if($request->user()->position === Position::GOALKEEPER, 403);

        $validated = $request->validate([
            'position' => ['required', Rule::in([Position::FIXED->value, Position::WINGER->value, Position::PIVOT->value])],
        ]);

        $request->user()->update(['position' => $validated['position']]);

        return back();
    })->name('profile.update-position');
    
    Route::get('/', [GameController::class, 'index'])->name('dashboard');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics');
    Route::post('/games/{game}/join', [GameController::class, 'join'])->name('games.join');
    Route::post('/games/{game}/quit', [GameController::class, 'quit'])->name('games.quit');

    Route::post('/games/{game}/add-players', [AdminGameController::class, 'addPlayers'])->name('games.add-players');
    Route::post('/admin/store-player', [AdminGameController::class, 'storePlayer'])->name('admin.store-player');
    Route::post('/games/{game}/store-guest', [AdminGameController::class, 'storeGuest'])->name('games.store-guest');
    Route::post('/games/{game}/scores', [AdminGameController::class, 'saveScores'])->name('games.save-scores');
    Route::post('/games/{game}/remove-from-team', [AdminGameController::class, 'removeFromTeam'])->name('games.remove-from-team');
    Route::post('/games/{game}/add-to-team', [AdminGameController::class, 'addToTeam'])->name('games.add-to-team');

    Route::get('/admin/players', [AdminPlayerController::class, 'index'])->name('admin.players');
    Route::post('/admin/players', [AdminPlayerController::class, 'store'])->name('admin.players.store');
    Route::post('/admin/players/{user}', [AdminPlayerController::class, 'update'])->name('admin.players.update');

    Route::get('/games/{game}/draft', [DraftController::class, 'show'])->name('games.draft');
    Route::post('/games/{game}/pick', [DraftController::class, 'pick'])->name('games.pick');
});
