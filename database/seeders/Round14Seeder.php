<?php

namespace Database\Seeders;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Round14Seeder extends Seeder
{
    /**
     * Novos jogadores a serem cadastrados (não-guests) caso não existam.
     */
    protected array $newPlayers = [
        '555195239461' => [
            'name'     => 'Paulo',
            'email'    => 'paulo@qnf.com',
            'position' => Position::WINGER,
        ],
        '555195905491' => [
            'name'     => 'Andre',
            'email'    => 'andre@qnf.com',
            'position' => Position::WINGER,
        ],
        '5551999294099' => [
            'name'     => 'Erick Pulga',
            'email'    => 'erick.pulga@qnf.com',
            'position' => Position::WINGER,
        ],
        '5551995363300' => [
            'name'     => 'Julio',
            'email'    => 'julio@qnf.com',
            'position' => Position::WINGER,
        ],
        '555195660805' => [
            'name'     => 'Gui',
            'email'    => 'gui@qnf.com',
            'position' => Position::GOALKEEPER,
        ],
    ];

    protected array $round = [
        'date'   => '2026-04-16',
        'scores' => ['green' => 2, 'yellow' => 3, 'blue' => 1],
        'green'  => [
            'captain' => '555196819002', // Brayan Dorneles ©️
            'players' => [
                '555195239461',         // Paulo 🔟
                '555199233129',         // Rogério
                '555199304836',         // Christian Steffens
                '555198249498',         // Zé 🧤
            ],
        ],
        'yellow' => [
            'captain' => '555196853483', // Motta ©️
            'players' => [
                '555198407569',         // Bryan Larruscain 🔟
                '555196114386',         // Eduardo Santana
                '555195905491',         // Andre
                'guest:gk:João',        // João 🧤
            ],
        ],
        'blue'   => [
            'captain' => '555196705353', // Vitor ©️
            'players' => [
                '5551999294099',        // Erick Pulga 🔟
                '555196304074',         // Gustavo Mendes
                '5551995363300',          // Julio
                '555195660805',         // Gui 🧤
            ],
        ],
    ];

    public function run(): void
    {
        $scoringService = app(ScoringService::class);

        $this->command->info('Seedando rodada 14...');

        DB::transaction(function () use ($scoringService) {
            $round = $this->round;

            $year = (int) substr($round['date'], 0, 4);
            $lastRound = Game::whereYear('date', $year)->max('round') ?? 0;

            $game = Game::create([
                'date'      => $round['date'],
                'opens_at'  => $round['date'] . ' 18:00:00',
                'closes_at' => $round['date'] . ' 21:00:00',
                'round'     => $lastRound + 1,
                'status'    => GameStatus::DONE,
            ]);

            $userCache = [];
            $allEntries = [];

            foreach (['green', 'yellow', 'blue'] as $color) {
                $allEntries[] = $round[$color]['captain'];
                foreach ($round[$color]['players'] as $phone) {
                    $allEntries[] = $phone;
                }
            }

            foreach (array_unique(array_filter($allEntries)) as $entry) {
                $userCache[$entry] = $this->resolveUser($entry);
            }

            $joinOrder = 1;
            foreach ($userCache as $user) {
                GamePlayer::firstOrCreate(
                    ['game_id' => $game->id, 'user_id' => $user->id],
                    ['joined_at' => $game->date->copy()->setTime(18, 0)->addSeconds($joinOrder++)]
                );
            }

            $colors = [
                'green'  => TeamColor::GREEN,
                'yellow' => TeamColor::YELLOW,
                'blue'   => TeamColor::BLUE,
            ];

            $pickIndex = 0;

            foreach ($colors as $colorKey => $colorEnum) {
                $captainPhone = $round[$colorKey]['captain'];
                $captain = $userCache[$captainPhone] ?? null;

                $team = Team::create([
                    'game_id'            => $game->id,
                    'color'              => $colorEnum,
                    'captain_user_id'    => $captain?->id,
                    'first_pick_user_id' => null,
                    'pick_order'         => match ($colorKey) {
                        'green'  => 1,
                        'yellow' => 2,
                        'blue'   => 3,
                    },
                    'score'              => $round['scores'][$colorKey],
                ]);

                foreach ($round[$colorKey]['players'] as $playerIndex => $phone) {
                    if (empty($phone)) continue;

                    $user = $userCache[$phone];

                    DraftPick::create([
                        'game_id'        => $game->id,
                        'round'          => intdiv($pickIndex, 3) + 1,
                        'pick_in_round'  => ($pickIndex % 3) + 1,
                        'team_color'     => $colorEnum,
                        'picked_user_id' => $user->id,
                        'picked_at'      => $game->date->copy()->setTime(18, 30)->addSeconds($pickIndex),
                    ]);

                    if ($playerIndex === 0) {
                        $team->update(['first_pick_user_id' => $user->id]);
                    }

                    $pickIndex++;
                }
            }

            $scoringService->calculateAndAssignPoints($game->refresh());
        });

        $this->command->info('Rodada 14 criada com sucesso!');
    }

    protected function resolveUser(string $entry): User
    {
        if (str_starts_with($entry, 'guest:')) {
            $rest = substr($entry, 6);

            if (str_starts_with($rest, 'gk:')) {
                $name = substr($rest, 3);
                $position = Position::GOALKEEPER;
            } else {
                $name = $rest;
                $position = Position::WINGER;
            }

            return User::firstOrCreate(
                ['name' => $name, 'guest' => true],
                [
                    'phone'    => 'guest-' . md5($name),
                    'email'    => 'guest-' . md5($name) . '@guest.local',
                    'role'     => 'player',
                    'position' => $position,
                    'password' => bcrypt('guest'),
                ]
            );
        }

        $user = User::where('phone', $entry)->first();

        if ($user) {
            return $user;
        }

        if (isset($this->newPlayers[$entry])) {
            $data = $this->newPlayers[$entry];

            return User::create([
                'name'     => $data['name'],
                'phone'    => $entry,
                'email'    => $data['email'],
                'role'     => 'player',
                'position' => $data['position'],
                'password' => bcrypt('qnf'),
            ]);
        }

        throw new \RuntimeException("Jogador com phone '{$entry}' não encontrado.");
    }
}
