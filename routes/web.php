<?php

use App\Http\Controllers\AdminGameController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminPlayerController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

// Mercado Pago webhook (public, no auth, no CSRF)
Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle'])
    ->name('webhooks.mercadopago');

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {

    Route::put('/profile/position', [ProfileController::class, 'updatePosition'])->name('profile.update-position');
    Route::put('/profile/whatsapp-notifications', [ProfileController::class, 'updateWhatsAppNotifications'])->name('profile.update-whatsapp-notifications');

    Route::get('/', [GameController::class, 'index'])->name('dashboard');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics');

    Route::prefix('games/{game}')->group(function () {
        Route::post('/join', [GameController::class, 'join'])->name('games.join');
        Route::post('/quit', [GameController::class, 'quit'])->name('games.quit');
        Route::post('/waitlist', [GameController::class, 'joinWaitlist'])->name('games.join-waitlist');
        Route::post('/add-players', [AdminGameController::class, 'addPlayers'])->name('games.add-players');
        Route::post('/store-guest', [AdminGameController::class, 'storeGuest'])->name('games.store-guest');
        Route::post('/scores', [AdminGameController::class, 'saveScores'])->name('games.save-scores');
        Route::post('/remove-player', [AdminGameController::class, 'removePlayer'])->name('games.remove-player');
        Route::post('/remove-from-team', [AdminGameController::class, 'removeFromTeam'])->name('games.remove-from-team');
        Route::post('/add-to-team', [AdminGameController::class, 'addToTeam'])->name('games.add-to-team');
        Route::get('/draft', [DraftController::class, 'show'])->name('games.draft');
        Route::post('/pick', [DraftController::class, 'pick'])->name('games.pick');
    });

    Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm'])->name('payments.confirm');

    Route::prefix('admin')->group(function () {
        Route::post('/store-player', [AdminGameController::class, 'storePlayer'])->name('admin.store-player');
        Route::get('/players', [AdminPlayerController::class, 'index'])->name('admin.players');
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('admin.payments');
        Route::post('/players', [AdminPlayerController::class, 'store'])->name('admin.players.store');
        Route::post('/players/{user}', [AdminPlayerController::class, 'update'])->name('admin.players.update');
        Route::post('/players/{user}/convert-guest', [AdminPlayerController::class, 'convertGuest'])->name('admin.players.convert-guest');
        Route::post('/players/{user}/suspend', [AdminPlayerController::class, 'suspend'])->name('admin.players.suspend');
        Route::post('/players/{user}/unsuspend', [AdminPlayerController::class, 'unsuspend'])->name('admin.players.unsuspend');
    });

    Route::prefix('api')->group(function () {
        Route::post('/whatsapp/send-test', [WhatsAppController::class, 'sendTest'])->name('api.whatsapp.send-test');
        Route::post('/week-team/random', [GameController::class, 'generateRandomWeekTeam'])->name('api.week-team.random');
    });
});
