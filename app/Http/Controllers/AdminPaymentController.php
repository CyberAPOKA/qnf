<?php

namespace App\Http\Controllers;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Models\Game;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminPaymentController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->role === 'admin', 403);

        $rounds = Game::whereIn('status', [GameStatus::DRAFTED->value, GameStatus::DONE->value])
            ->orderByDesc('round')
            ->select('id', 'round', 'date', 'status')
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'round' => $game->round,
                'date' => $game->date->format('d/m/Y'),
                'status' => $game->status->value,
            ]);

        $selectedGameId = $request->input('game_id', $rounds->first()['id'] ?? null);

        $payments = [];
        if ($selectedGameId) {
            $payments = $this->getPaymentsForGame((int) $selectedGameId);
        }

        return Inertia::render('AdminPayments', [
            'rounds' => $rounds,
            'selected_game_id' => $selectedGameId ? (int) $selectedGameId : null,
            'payments' => $payments,
        ]);
    }

    private function getPaymentsForGame(int $gameId): array
    {
        // Busca todos os jogadores de linha (não-goleiros, não-convidados) que jogaram
        $linePlayers = DB::table('game_players')
            ->join('users', 'game_players.user_id', '=', 'users.id')
            ->where('game_players.game_id', $gameId)
            ->where('game_players.dropped_out', false)
            ->where('users.position', '!=', Position::GOALKEEPER->value)
            ->where('users.guest', false)
            ->select('users.id', 'users.name')
            ->orderBy('users.name')
            ->get();

        $paymentsMap = Payment::where('game_id', $gameId)
            ->get()
            ->keyBy('user_id');

        return $linePlayers->map(function ($player) use ($paymentsMap) {
            $payment = $paymentsMap->get($player->id);

            return [
                'user_id' => $player->id,
                'user_name' => $player->name,
                'payment_id' => $payment?->id,
                'paid_at' => $payment?->paid_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'penalty_rounds' => $payment?->penalty_rounds ?? 0,
            ];
        })->all();
    }
}
