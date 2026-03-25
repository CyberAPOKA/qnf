<?php

namespace Database\Seeders;

use App\Enums\GameStatus;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\ScoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Enums\Position;

class GameHistorySeeder extends Seeder
{
    /**
     * ============================================================
     *  COMO PREENCHER:
     * ============================================================
     *
     *  Cada rodada é um array com:
     *    - 'date'   => data da quinta-feira (formato 'Y-m-d')
     *    - 'scores' => placar final [green, yellow, blue]
     *    - 'green', 'yellow', 'blue' => times, cada um com:
     *        - 'captain' => phone do capitão ©️
     *        - 'players' => lista de phones dos jogadores draftados
     *                       O 1º da lista é o first_pick (🔟)
     *                       Use 'guest:Nome' para convidados (ala)
     *                       Use 'guest:gk:Nome' para convidados goleiros
     *
     *  Referência rápida de phones (do UserSeeder):
     *
     *  ADMIN:
     *    Douglas Hensel        => 555199236139
     *
     *  GOLEIROS:
     *    Mateus Nogueira       => 555198249498
     *    Silvio Schneider      => 555199562969
     *    Ailon Ribeiro         => 555197987109
     *
     *  FIXOS:
     *    Willian Guilherme     => 555197336092
     *    Augusto Martins       => 555199706231
     *    Mateus Hensel         => 555195038604
     *    Caio Drehmer          => 555180141784
     *    Salenave              => 555195486465
     *    Rodrigo               => 555198928569
     *    Jean Barbosa          => 555195864516
     *
     *  ALAS:
     *    Bryan Larruscain      => 555198407569
     *    Gabriel Macek         => 555197993094
     *    Guto Cenci            => 555199113865
     *    Nycolas Dias          => 555198456641
     *    Isaque                => 555193103772
     *    Motta                 => 555196853483
     *    Anderson              => 555180433478
     *    Brayan Dorneles       => 555196819002
     *    Daniel                => 555196272812
     *    Deivison Mattos       => 555180240904
     *    Gustavo Mendes        => 555196304074
     *    João Vicente          => 555199502165
     *    Lucas Fontes          => 555198611915
     *    Pedro Pereira         => 555195002348
     *    Rogério               => 555199233129
     *    Roth                  => 555199885564
     *    Vitor                 => 555196705353
     *
     *  PIVÔS:
     *    Christian Steffens    => 555199304836
     *    Eduardo Santana       => 555196114386
     *    Jair                  => 555199329888
     *
     * ============================================================
     */
    protected array $rounds = [

        // ─── RODADA 01 ────────────────────────────────────
        [
            'date'   => '2026-01-16',
            'scores' => ['green' => 4, 'yellow' => 2, 'blue' => 1],
            'green'  => [
                'captain' => '555195002348', // Pedro Pereira ©️
                'players' => [
                    '555195038604', // Mateus Hensel 🔟
                    '555196819002', // Brayan Dorneles
                    '555196114386', // Eduardo Santana
                    'guest:gk:Gui',    // Gui - convidado 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555196705353', // Vitor ©️
                'players' => [
                    '555195864516', // Jean Barbosa 🔟
                    '555198611915', // Lucas Fontes
                    '555196272812', // Daniel
                    '555198456641', // Nycolas Dias
                ],
            ],
            'blue'   => [
                'captain' => '555196304074', // Gustavo Mendes ©️
                'players' => [
                    '555180141784', // Caio Drehmer 🔟
                    '555198928569', // Rodrigo
                    '555198249498', // Mateus Nogueira 🧤
                    '555199885564', // Roth
                ],
            ],
        ],

        // ─── RODADA 02 ────────────────────────────────────
        [
            'date'   => '2026-01-23',
            'scores' => ['green' => 1, 'yellow' => 1, 'blue' => 3],
            'green'  => [
                'captain' => '555199885564', // Roth ©️
                'players' => [
                    '555196819002', // Brayan Dorneles
                    '555199233129', // Rogério
                    '555198456641', // Nycolas Dias
                    '555199562969', // Silvio Schneider 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555199113865', // Guto Cenci ©️
                'players' => [
                    '555199329888', // Jair
                    '555196705353', // Vitor
                    '555198249498', // Mateus Nogueira 🧤
                    '555195002348', // Pedro Pereira
                ],
            ],
            'blue'   => [
                'captain' => '555197336092', // Willian Guilherme ©️
                'players' => [
                    '555196272812', // Daniel
                    'guest:gk:Gui',    // Gui - convidado 🧤
                    '555196114386', // Eduardo Santana
                    '555198407569', // Bryan Larruscain
                ],
            ],
        ],

        // ─── RODADA 03 ────────────────────────────────────
        [
            'date'   => '2026-01-30',
            'scores' => ['green' => 3, 'yellow' => 2, 'blue' => 2],
            'green'  => [
                'captain' => '555196819002', // Brayan Dorneles ©️
                'players' => [
                    '555180141784', // Caio Drehmer 🔟
                    '555199562969', // Silvio Schneider 🧤
                    '555199113865', // Guto Cenci
                    '555195002348', // Pedro Pereira
                ],
            ],
            'yellow' => [
                'captain' => '555196114386', // Eduardo Santana ©️
                'players' => [
                    '555195864516', // Jean Barbosa 🔟
                    '555198249498', // Mateus Nogueira 🧤
                    '555197336092', // Willian Guilherme
                    '555196304074', // Gustavo Mendes
                ],
            ],
            'blue'   => [
                'captain' => '555196705353', // Vitor ©️
                'players' => [
                    '555198407569', // Bryan Larruscain 🔟
                    'guest:gk:Gui',    // Gui - convidado 🧤
                    '555199885564', // Roth
                    '555199304836', // Christian Steffens
                ],
            ],
        ],

        // ─── RODADA 04 ────────────────────────────────────
        [
            'date'   => '2026-02-06',
            'scores' => ['green' => 3, 'yellow' => 3, 'blue' => 1],
            'green'  => [
                'captain' => '555198407569', // Bryan Larruscain ©️
                'players' => [
                    '555193103772', // Isaque
                    '555198456641', // Nycolas Dias
                    '555199304836', // Christian Steffens
                    'guest:gk:Gui',    // Gui - convidado 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555196304074', // Gustavo Mendes ©️
                'players' => [
                    '555197336092', // Willian Guilherme
                    '555199562969', // Silvio Schneider 🧤
                    '555196272812', // Daniel
                    '555198928569', // Rodrigo
                ],
            ],
            'blue'   => [
                'captain' => '555199502165', // João Vicente ©️
                'players' => [
                    '555196114386', // Eduardo Santana
                    '555198249498', // Mateus Nogueira 🧤
                    '555199885564', // Roth
                    '555196819002', // Brayan Dorneles
                ],
            ],
        ],

        // ─── RODADA 05 ────────────────────────────────────
        [
            'date'   => '2026-02-13',
            'scores' => ['green' => 2, 'yellow' => 3, 'blue' => 0],
            'green'  => [
                'captain' => '555196272812', // Daniel ©️
                'players' => [
                    '555195038604', // Mateus Hensel
                    '555196705353', // Vitor
                    '555198928569', // Rodrigo
                    '555198249498', // Mateus Nogueira 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555199233129', // Rogério ©️
                'players' => [
                    '555196304074',   // Gustavo Mendes
                    '555199304836',   // Christian Steffens
                    '555196114386',   // Eduardo Santana
                    'guest:gk:Cassiano', // Cassiano - convidado 🧤
                ],
            ],
            'blue'   => [
                'captain' => '555193103772', // Isaque ©️
                'players' => [
                    '555198407569', // Bryan Larruscain
                    '555199502165', // João Vicente
                    '555199885564', // Roth
                    '555199562969', // Silvio Schneider 🧤
                ],
            ],
        ],

        // ─── RODADA 06 ────────────────────────────────────
        [
            'date'   => '2026-02-20',
            'scores' => ['green' => 1, 'yellow' => 3, 'blue' => 4],
            'green'  => [
                'captain' => '555196819002', // Brayan Dorneles ©️
                'players' => [
                    '555199885564',    // Roth
                    '555199233129',    // Rogério
                    '555199329888',    // Jair
                    'guest:gk:Cristiano', // Cristiano - convidado 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555199304836', // Christian Steffens ©️
                'players' => [
                    '555198928569', // Rodrigo
                    'guest:Jako',   // Jako - convidado
                    '555198249498', // Mateus Nogueira 🧤
                    '555196705353', // Vitor
                ],
            ],
            'blue'   => [
                'captain' => '555195038604', // Mateus Hensel ©️
                'players' => [
                    '555198407569', // Bryan Larruscain
                    '555196272812', // Daniel
                    '555196304074', // Gustavo Mendes
                    '555199562969', // Silvio Schneider 🧤
                ],
            ],
        ],

        // ─── RODADA 07 ────────────────────────────────────
        [
            'date'   => '2026-02-27',
            'scores' => ['green' => 3, 'yellow' => 2, 'blue' => 2],
            'green'  => [
                'captain' => '555196272812', // Daniel ©️
                'players' => [
                    '555195038604', // Mateus Hensel
                    '555198407569', // Bryan Larruscain
                    '555196114386', // Eduardo Santana
                    '555199562969', // Silvio Schneider 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555196705353', // Vitor ©️
                'players' => [
                    '555198928569',    // Rodrigo
                    'guest:gk:Cristiano', // Cristiano - convidado 🧤
                    '555196304074',    // Gustavo Mendes
                    '555199304836',    // Christian Steffens
                ],
            ],
            'blue'   => [
                'captain' => '555199113865', // Guto Cenci ©️
                'players' => [
                    '555199885564',        // Roth
                    '555198249498',        // Mateus Nogueira 🧤
                    '555196819002',        // Brayan Dorneles
                    'guest:Erick Pulga',   // Erick Pulga - convidado
                ],
            ],
        ],

        // ─── RODADA 08 ────────────────────────────────────
        [
            'date'   => '2026-03-06',
            'scores' => ['green' => 3, 'yellow' => 3, 'blue' => 1],
            'green'  => [
                'captain' => '555195002348', // Pedro Pereira ©️
                'players' => [
                    '555199304836', // Christian Steffens
                    'guest:Jonathan',  // Jonathan - convidado
                    '555196272812', // Daniel
                    'guest:gk:Cristiano', // Cristiano - convidado 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555196114386', // Eduardo Santana ©️
                'players' => [
                    '555198407569', // Bryan Larruscain
                    '555199562969', // Silvio Schneider 🧤
                    'guest:Julio',  // Julio - convidado
                    'guest:Andre',  // Andre - convidado
                ],
            ],
            'blue'   => [
                'captain' => '555196304074', // Gustavo Mendes ©️
                'players' => [
                    '555199113865', // Guto Cenci
                    '555198249498', // Mateus Nogueira 🧤
                    '555199502165', // João Vicente
                    '555196705353', // Vitor
                ],
            ],
        ],

        // ─── RODADA 09 ────────────────────────────────────
        [
            'date'   => '2026-03-13',
            'scores' => ['green' => 3, 'yellow' => 0, 'blue' => 5],
            'green'  => [
                'captain' => '555198407569', // Bryan Larruscain ©️
                'players' => [
                    '555193103772', // Isaque 🔟
                    'guest:Erick Pulga', // Erick Pulga
                    '555196272812', // Daniel
                    'guest:gk:Motta', // Motta 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555196705353', // Vitor ©️
                'players' => [
                    '555199304836', // Christian Steffens 🔟
                    '555196819002', // Brayan Dorneles
                    '555199502165', // João Vicente
                    'guest:gk:Cristiano', // Cristiano 🧤
                ],
            ],
            'blue'   => [
                'captain' => '555199113865', // Guto Cenci ©️
                'players' => [
                    '555199329888', // Jair 🔟
                    '555196304074', // Gustavo Mendes
                    '555199885564', // Roth
                    '555198249498', // Mateus Nogueira 🧤
                ],
            ],
        ],

        // ─── RODADA 10 ────────────────────────────────────
        [
            'date'   => '2026-03-20',
            'scores' => ['green' => 5, 'yellow' => 1, 'blue' => 0],
            'green'  => [
                'captain' => '555199329888', // Jair Benhur ©️
                'players' => [
                    '555199304836', // Christian Steffens 🔟
                    '555198928569', // Rodrigo (Beto)
                    '555199113865', // Guto Cenci
                    'guest:gk:João', // João 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555199885564', // Roth ©️
                'players' => [
                    '555198407569', // Bryan Larruscain 🔟
                    '555196819002', // Brayan Dorneles
                    '555198456641', // Nycolas Dias
                    'guest:gk:Gui', // Gui 🧤
                ],
            ],
            'blue'   => [
                'captain' => '555196272812', // Daniel ©️
                'players' => [
                    '555196114386', // Eduardo Santana 🔟
                    '555196304074', // Gustavo Mendes
                    '555198249498', // Mateus Nogueira (Zé) 🧤
                    '555196705353', // Vitor (BTP)
                ],
            ],
        ],

        // ─── RODADA 11 ─────────────────────────
        [
            'date'   => '2026-03-27',
            'scores' => ['green' => 1, 'yellow' => 3, 'blue' => 6],
            'green'  => [
                'captain' => '555195486465', // Salenave ©️
                'players' => [
                    '555198928569', // Rodrigo (Beto) 🔟
                    'guest:Julio Brill',
                    '555196819002', // Brayan Dorneles
                    '555199562969', // Silvio Schneider 🧤
                ],
            ],
            'yellow' => [
                'captain' => '555199304836', // Christian Steffens ©️
                'players' => [
                    '555199329888', // Jair Benhur 🔟
                    '555196853483', // Motta
                    '555196272812', // Daniel
                    'guest:gk:João',
                ],
            ],
            'blue'   => [
                'captain' => '555199113865', // Guto Cenci ©️
                'players' => [
                    '555195864516', // Jean Barbosa 🔟
                    '555199233129', // Rogério
                    '555198249498', // Mateus Nogueira (Zé) 🧤
                    '555199885564', // Roth
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $scoringService = app(ScoringService::class);

        foreach ($this->rounds as $index => $round) {
            $this->command->info("Seedando rodada " . ($index + 1) . "...");
            $this->seedRound($round, $scoringService);
        }

        $this->command->info('Histórico de jogos criado com sucesso!');
    }

    protected function seedRound(array $round, ScoringService $scoringService): void
    {
        // Pula rodadas não preenchidas
        $allPhones = collect(['green', 'yellow', 'blue'])
            ->flatMap(fn($color) => [$round[$color]['captain'], ...$round[$color]['players']])
            ->filter()
            ->values();

        if ($allPhones->isEmpty()) {
            $this->command->warn("  Rodada {$round['date']} vazia, pulando...");
            return;
        }

        DB::transaction(function () use ($round, $scoringService) {
            // 1. Criar o Game
            $year = (int) substr($round['date'], 0, 4);
            $lastRound = Game::whereYear('date', $year)->max('round') ?? 0;

            $game = Game::create([
                'date'      => $round['date'],
                'opens_at'  => $round['date'] . ' 18:00:00',
                'closes_at' => $round['date'] . ' 21:00:00',
                'round'     => $lastRound + 1,
                'status'    => GameStatus::DONE,
            ]);

            // 2. Resolver todos os users (por phone ou criar guest)
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

            // 3. Criar GamePlayer para todos os 15 jogadores
            $joinOrder = 1;
            foreach ($userCache as $user) {
                GamePlayer::firstOrCreate(
                    ['game_id' => $game->id, 'user_id' => $user->id],
                    ['joined_at' => $game->date->copy()->setTime(18, 0)->addSeconds($joinOrder++)]
                );
            }

            // 4. Criar Teams e DraftPicks
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
                    'game_id'           => $game->id,
                    'color'             => $colorEnum,
                    'captain_user_id'   => $captain?->id,
                    'first_pick_user_id' => null,
                    'pick_order'        => match ($colorKey) {
                        'green'  => 1,
                        'yellow' => 2,
                        'blue'   => 3,
                    },
                    'score'             => $round['scores'][$colorKey],
                ]);

                foreach ($round[$colorKey]['players'] as $playerIndex => $phone) {
                    if (empty($phone)) continue;

                    $user = $userCache[$phone];

                    $pick = DraftPick::create([
                        'game_id'        => $game->id,
                        'round'          => intdiv($pickIndex, 3) + 1,
                        'pick_in_round'  => ($pickIndex % 3) + 1,
                        'team_color'     => $colorEnum,
                        'picked_user_id' => $user->id,
                        'picked_at'      => $game->date->copy()->setTime(18, 30)->addSeconds($pickIndex),
                    ]);

                    // Primeiro jogador da lista é o first_pick
                    if ($playerIndex === 0) {
                        $team->update(['first_pick_user_id' => $user->id]);
                    }

                    $pickIndex++;
                }
            }

            // 5. Calcular pontos baseado nos placares
            $scoringService->calculateAndAssignPoints($game->refresh());
        });
    }

    protected function resolveUser(string $entry): User
    {
        // Convidados goleiro: 'guest:gk:Nome'
        // Convidados linha:   'guest:Nome' (padrão = ala)
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

        // Jogadores normais: busca por phone
        $user = User::where('phone', $entry)->first();

        if (! $user) {
            throw new \RuntimeException("Jogador com phone '{$entry}' não encontrado. Verifique o UserSeeder.");
        }

        return $user;
    }
}
