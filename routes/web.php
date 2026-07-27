<?php

use App\Http\Controllers\AdminGameController;
use App\Http\Controllers\AdminInstagramController;
use App\Http\Controllers\AdminPaymentController;
use App\Http\Controllers\AdminPlayerController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecController;
use App\Http\Controllers\RecV2Controller;
use App\Http\Controllers\RoundsController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TimelineController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\YouTubeController;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Support\Facades\Route;

// Mercado Pago webhook (public, no auth, no CSRF)
Route::post('/webhooks/mercadopago', [MercadoPagoWebhookController::class, 'handle'])
    ->name('webhooks.mercadopago');

Route::middleware(['auth:sanctum', config('jetstream.auth_session')])->group(function () {

    Route::put('/profile/position', [ProfileController::class, 'updatePosition'])->name('profile.update-position');
    Route::put('/profile/whatsapp-notifications', [ProfileController::class, 'updateWhatsAppNotifications'])->name('profile.update-whatsapp-notifications');
    Route::put('/profile/instagram', [ProfileController::class, 'updateInstagram'])->name('profile.update-instagram');
    Route::put('/profile/music', [ProfileController::class, 'updateMusic'])->name('profile.update-music');

    Route::get('/', [GameController::class, 'index'])->name('dashboard');
    Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics');
    Route::get('/rounds', [RoundsController::class, 'index'])->name('rounds');
    Route::get('/timeline', [TimelineController::class, 'index'])->name('timeline');

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

        Route::get('/rec', [RecController::class, 'show'])->name('games.rec');
        Route::post('/rec/start', [RecController::class, 'start'])->name('games.rec.start');
        Route::post('/rec/heartbeat', [RecController::class, 'heartbeat'])->name('games.rec.heartbeat');
        Route::post('/rec/stop', [RecController::class, 'stop'])->name('games.rec.stop');
        Route::post('/rec/save', [RecController::class, 'save'])->name('games.rec.save');
        Route::post('/rec/upload', [RecController::class, 'upload'])->name('games.rec.upload');

        Route::post('/rec/sessions', [RecV2Controller::class, 'startSession'])->name('games.rec.sessions.start');
        Route::post('/rec/sessions/{session}/heartbeat', [RecV2Controller::class, 'heartbeat'])->name('games.rec.sessions.heartbeat');
        Route::post('/rec/sessions/{session}/stop', [RecV2Controller::class, 'stopSession'])->name('games.rec.sessions.stop');
        Route::post('/rec/sessions/{session}/segments', [RecV2Controller::class, 'uploadSegment'])->name('games.rec.sessions.segments');
        Route::get('/rec/sessions/{session}/segments/status', [RecV2Controller::class, 'segmentStatus'])->name('games.rec.sessions.segments.status');
        Route::get('/rec/sessions/{session}/save-requests/pending', [RecV2Controller::class, 'pendingSaves'])->name('games.rec.sessions.pending-saves');
        Route::post('/rec/sessions/{session}/save-requests/{saveRequest}/ack', [RecV2Controller::class, 'ackSave'])->name('games.rec.sessions.ack-save');
        Route::get('/rec/sessions/{session}/recovery-requests', [RecV2Controller::class, 'recoveryRequests'])->name('games.rec.sessions.recovery');
        Route::post('/rec/save-requests', [RecV2Controller::class, 'createSave'])->name('games.rec.save-requests.store');
        Route::get('/rec/save-requests/{saveRequest}', [RecV2Controller::class, 'showSave'])->name('games.rec.save-requests.show');
    });

    Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm'])->name('payments.confirm');

    Route::prefix('admin')->group(function () {
        Route::get('/players', [AdminPlayerController::class, 'index'])->name('admin.players');
        Route::get('/payments', [AdminPaymentController::class, 'index'])->name('admin.payments');
        Route::get('/instagram', [AdminInstagramController::class, 'index'])->name('admin.instagram');
        Route::post('/instagram/{publication}/retry', [AdminInstagramController::class, 'retry'])->name('admin.instagram.retry');

        Route::middleware(HandlePrecognitiveRequests::class)->group(function () {
            Route::post('/players', [AdminPlayerController::class, 'store'])->name('admin.players.store');
            Route::post('/players/{user}', [AdminPlayerController::class, 'update'])->name('admin.players.update');
            Route::post('/players/{user}/convert-guest', [AdminPlayerController::class, 'convertGuest'])->name('admin.players.convert-guest');
        });

        Route::post('/players/{user}/suspend', [AdminPlayerController::class, 'suspend'])->name('admin.players.suspend');
        Route::post('/players/{user}/unsuspend', [AdminPlayerController::class, 'unsuspend'])->name('admin.players.unsuspend');
        Route::post('/players/{user}/cards', [AdminPlayerController::class, 'storeCard'])->name('admin.players.cards.store');
        Route::delete('/players/{user}', [AdminPlayerController::class, 'destroy'])->name('admin.players.destroy');
    });

    Route::prefix('api')->group(function () {
        Route::get('/youtube/search', [YouTubeController::class, 'search'])->name('api.youtube.search');
        Route::get('/youtube/videos/{videoId}', [YouTubeController::class, 'show'])->name('api.youtube.show');
        Route::post('/whatsapp/send-test', [WhatsAppController::class, 'sendTest'])->name('api.whatsapp.send-test');
        Route::post('/week-team/random', [GameController::class, 'generateRandomWeekTeam'])->name('api.week-team.random');
        Route::post('/captains/generate', [GameController::class, 'generateCaptainsImage'])->name('api.captains.generate');
        Route::post('/lineups/generate', [GameController::class, 'generateLineupsImage'])->name('api.lineups.generate');
        Route::post('/ranking/generate', [GameController::class, 'generateRankingImage'])->name('api.ranking.generate');
        Route::post('/payments/create-all', [GameController::class, 'createPayments'])->name('api.payments.create-all');
        Route::get('/round-data', [GameController::class, 'getRoundData'])->name('api.round-data');
        Route::post('/games/{game}/regenerate-week-team', [GameController::class, 'regenerateWeekTeam'])->name('api.games.regenerate-week-team');
        Route::post('/games/regenerate-all-week-teams', [GameController::class, 'regenerateAllWeekTeams'])->name('api.games.regenerate-all-week-teams');
    });
});
