<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_ID = 'laura.c@example.net';

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.whatsapp.webhook_secret' => self::SECRET,
            'services.whatsapp.group_id' => self::GROUP_ID,
            'services.whatsapp.command_cooldown_seconds' => 10,
            'services.whatsapp.commands_global_cooldown_seconds' => 3600,
            'services.whatsapp.lineup_cooldown_seconds' => 3600,
            'services.whatsapp.lineup_unlimited_phone' => '555199304836',
        ]);
    }

    public function test_play_adds_the_sender_to_the_open_game(): void
    {
        $player = $this->player(['phone' => '555199294672', 'name' => 'Joao Silva']);
        $game = $this->openGame();

        $this->postCommand('/play', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', 'Joao, você entrou na partida.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->whereNull('waitlist_at')->exists()
        );
    }

    public function test_jogar_is_an_alias_of_play(): void
    {
        $player = $this->player(['phone' => '555198888888']);
        $game = $this->openGame();

        $this->postCommand('/jogar', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->exists()
        );
    }

    public function test_play_adds_the_sender_to_the_waitlist_when_the_game_is_full(): void
    {
        $player = $this->player(['name' => 'Maria Souza']);
        $game = $this->fullGameWithLinePlayers(12);

        $this->postCommand('/play', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Maria, você está na fila de espera (1º).');

        $record = GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first();

        $this->assertNotNull($record?->waitlist_at);
        $this->assertFalse($record->dropped_out);
    }

    public function test_play_tells_the_player_when_already_inscribed(): void
    {
        $player = $this->player(['name' => 'Pedro Alves']);
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/play', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Pedro, você já está inscrito nesta partida.');
    }

    public function test_desistir_marks_the_player_as_dropped_out(): void
    {
        $player = $this->player(['name' => 'Ana Lima']);
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/desistir', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Ana, você desistiu da partida.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first()->dropped_out
        );
    }

    public function test_quit_is_an_alias_of_desistir(): void
    {
        $player = $this->player();
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/quit', $player)->assertOk()->assertJsonPath('status', 'ok');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first()->dropped_out
        );
    }

    public function test_quit_removes_the_player_from_the_waitlist(): void
    {
        $player = $this->player(['name' => 'Carla Dias']);
        $other = $this->player();
        $game = $this->fullGameWithLinePlayers(12);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'waitlist_at' => now(),
        ]);
        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $other->id,
            'waitlist_at' => now()->addSecond(),
        ]);

        $this->postCommand('/quit', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Carla, você saiu da fila de espera.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->first()->dropped_out
        );
        $this->assertNotNull(
            GamePlayer::where('game_id', $game->id)->where('user_id', $other->id)->first()->waitlist_at
        );
        $this->assertSame(GameStatus::FULL, $game->fresh()->status);
    }

    public function test_quit_promotes_the_next_waitlisted_player(): void
    {
        $leaving = $this->player(['name' => 'Joao']);
        $waiting = $this->player(['name' => 'Maria']);
        $game = $this->fullGameWithLinePlayers(11);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $leaving->id,
            'joined_at' => now()->subMinute(),
        ]);

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $waiting->id,
            'waitlist_at' => now(),
        ]);

        $game->update(['status' => GameStatus::FULL]);

        $this->postCommand('/desistir', $leaving)
            ->assertOk()
            ->assertJsonPath('reply', 'Joao, você desistiu da partida. Maria subiu da fila.');

        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('user_id', $leaving->id)->first()->dropped_out);
        $this->assertNull(GamePlayer::where('game_id', $game->id)->where('user_id', $waiting->id)->first()->waitlist_at);
        $this->assertNotNull(GamePlayer::where('game_id', $game->id)->where('user_id', $waiting->id)->first()->joined_at);
    }

    public function test_commands_lists_player_commands(): void
    {
        $player = $this->player();
        $this->openGame();

        $reply = $this->postCommand('/commands', $player)
            ->assertOk()
            ->json('reply');

        $this->assertStringContainsString('/jogar ou /play', $reply);
        $this->assertStringContainsString('/desistir ou /quit', $reply);
        $this->assertStringContainsString('/comandos ou /commands', $reply);
        $this->assertStringContainsString('/lineup {cor} {voz}', $reply);
        $this->assertStringNotContainsString('/add', $reply);
    }

    public function test_comandos_is_an_alias_and_lists_admin_commands_for_admins(): void
    {
        $admin = $this->admin(['name' => 'Admin']);
        $this->openGame();

        $reply = $this->postCommand('/comandos', $admin)->assertOk()->json('reply');

        $this->assertStringContainsString('/add {número}', $reply);
        $this->assertStringContainsString('/remove {número}', $reply);
    }

    public function test_play_and_jogar_share_the_same_cooldown(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/play', $player)->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/jogar', $player, ['message_id' => 'second'])
            ->assertOk()
            ->assertJsonPath('reply', 'Aguarde um pouco antes de enviar este comando de novo.');
    }

    public function test_quit_and_desistir_share_the_same_cooldown(): void
    {
        $player = $this->player();
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/desistir', $player)->assertOk();

        $this->postCommand('/quit', $player, ['message_id' => 'second-quit'])
            ->assertOk()
            ->assertJsonPath('reply', 'Aguarde um pouco antes de enviar este comando de novo.');
    }

    public function test_commands_has_a_global_cooldown_for_regular_players(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->openGame();

        $this->postCommand('/commands', $first)->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/comandos', $second)
            ->assertOk()
            ->assertJsonPath('reply', 'Os comandos já foram listados recentemente. Tente de novo mais tarde.');
    }

    public function test_admin_bypasses_the_global_commands_cooldown(): void
    {
        $player = $this->player();
        $admin = $this->admin();
        $this->openGame();

        $this->postCommand('/commands', $player)->assertOk();

        $this->postCommand('/comandos', $admin)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertStringContainsString('/add', $this->postCommand('/comandos', $admin, ['message_id' => 'admin-2'])->json('reply'));
    }

    public function test_commands_global_cooldown_expires_after_one_hour(): void
    {
        $first = $this->player();
        $second = $this->player();
        $this->openGame();

        $this->postCommand('/commands', $first)->assertOk();

        $this->travel(1)->hours();

        $this->postCommand('/comandos', $second)
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_admin_can_add_a_player_by_phone(): void
    {
        $admin = $this->admin(['name' => 'Chefe']);
        $target = $this->player(['name' => 'Rafael Costa', 'phone' => '555199111111']);
        $game = $this->openGame();

        $this->postCommand('/add +55 51 9911-1111', $admin)
            ->assertOk()
            ->assertJsonPath('reply', 'Chefe, Rafael foi adicionado à partida.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $target->id)->whereNull('waitlist_at')->exists()
        );
    }

    public function test_admin_can_remove_a_player_by_phone(): void
    {
        $admin = $this->admin(['name' => 'Chefe']);
        $target = $this->player(['name' => 'Rafael Costa', 'phone' => '555199222222']);
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $target->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/remove 5199222222', $admin)
            ->assertOk()
            ->assertJsonPath('reply', 'Chefe, Rafael foi removido da partida.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $target->id)->first()->dropped_out
        );
    }

    public function test_admin_can_add_a_player_by_mention(): void
    {
        $admin = $this->admin(['name' => 'Chefe']);
        $target = $this->player(['name' => 'Lucas Pinto', 'phone' => '555199333333']);
        $game = $this->openGame();

        $this->postCommand('/add @Lucas', $admin, [
            'mentioned_phones' => ['555199333333'],
            'mentioned_ids' => ['xavier.y@example.org'],
        ])
            ->assertOk()
            ->assertJsonPath('reply', 'Chefe, Lucas foi adicionado à partida.');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $target->id)->exists()
        );
    }

    public function test_admin_can_remove_a_player_by_mention(): void
    {
        $admin = $this->admin(['name' => 'Chefe']);
        $target = $this->player(['name' => 'Lucas Pinto', 'phone' => '555199444444']);
        $game = $this->openGame();

        GamePlayer::create([
            'game_id' => $game->id,
            'user_id' => $target->id,
            'joined_at' => now(),
        ]);

        $this->postCommand('/remove @Lucas', $admin, [
            'mentioned_phones' => ['555199444444'],
            'mentioned_ids' => ['xavier.y@example.org'],
        ])
            ->assertOk()
            ->assertJsonPath('reply', 'Chefe, Lucas foi removido da partida.');
    }

    public function test_regular_player_cannot_run_admin_commands(): void
    {
        $player = $this->player();
        $target = $this->player(['phone' => '555199555555']);
        $this->openGame();

        $this->postCommand('/add 555199555555', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Você não tem permissão para este comando.');

        $this->assertFalse(
            GamePlayer::where('user_id', $target->id)->exists()
        );
    }

    public function test_unknown_phone_does_not_run_the_command(): void
    {
        $this->openGame();

        $this->postCommand('/play', null, [
            'author_phone' => '555199000000',
            'author_id' => 'xavier.y@example.org',
        ])
            ->assertOk()
            ->assertJsonPath('reply', 'Este número não está cadastrado no QNF.');

        $this->assertSame(0, GamePlayer::count());
    }

    public function test_unauthenticated_payload_is_rejected(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postJson(route('webhooks.whatsapp'), $this->payload('/play', $player))
            ->assertUnauthorized();

        $this->withToken('wrong-secret')
            ->postJson(route('webhooks.whatsapp'), $this->payload('/play', $player))
            ->assertUnauthorized();

        $this->assertSame(0, GamePlayer::count());
    }

    public function test_messages_from_another_group_are_ignored(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/play', $player, ['chat_id' => 'ethan.b@example.com'])
            ->assertOk()
            ->assertJsonPath('status', 'ignored')
            ->assertJsonPath('reply', null);

        $this->assertSame(0, GamePlayer::count());
    }

    public function test_plain_group_messages_are_ignored(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('boa noite galera', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ignored')
            ->assertJsonPath('reply', null);

        $this->assertSame(0, GamePlayer::count());
    }

    public function test_duplicate_message_ids_are_not_processed_twice(): void
    {
        $player = $this->player();
        $game = $this->openGame();

        $this->postCommand('/play', $player, ['message_id' => 'true_ABC'])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->postCommand('/play', $player, ['message_id' => 'true_ABC'])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('reply', null);

        $this->assertSame(1, GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->count());
    }

    public function test_thirteenth_line_player_is_rejected_while_the_list_is_open(): void
    {
        $player = $this->player(['name' => 'Treze']);
        $this->fullGameWithLinePlayers(12, GameStatus::OPEN);

        $this->postCommand('/play', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'As vagas para jogadores de linha estão esgotadas.');
    }

    public function test_two_waitlist_joins_keep_order(): void
    {
        $first = $this->player(['name' => 'Primeiro']);
        $second = $this->player(['name' => 'Segundo']);
        $game = $this->fullGameWithLinePlayers(12);

        $this->postCommand('/play', $first)->assertOk()->assertJsonPath('reply', 'Primeiro, você está na fila de espera (1º).');
        $this->postCommand('/play', $second)->assertOk()->assertJsonPath('reply', 'Segundo, você está na fila de espera (2º).');

        $ordered = GamePlayer::where('game_id', $game->id)
            ->whereNotNull('waitlist_at')
            ->orderBy('waitlist_at')
            ->pluck('user_id')
            ->all();

        $this->assertSame([$first->id, $second->id], $ordered);
    }

    public function test_bot_messages_are_ignored(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/play', $player, ['from_me' => true])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, GamePlayer::count());
    }

    public function test_add_without_a_number_returns_an_invalid_number_message(): void
    {
        $admin = $this->admin();
        $this->openGame();

        $this->postCommand('/add', $admin)
            ->assertOk()
            ->assertJsonPath('reply', 'Informe um número válido. Ex.: /add 51999999999');
    }

    public function test_lineup_returns_generated_audio_path_for_the_current_round(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $player = $this->player();
        $this->draftedBlueTeam();

        $response = $this->postCommand('/lineup blue lula', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null)
            ->assertJsonPath('cleanup_audio', true);

        $path = $response->json('audio_path');

        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->assertSame('ID3lineup-audio', file_get_contents($path));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fish.audio/v1/tts'
                && $request['reference_id'] === 'voice-lula-id'
                && str_contains($request['text'], 'Escalação do time azul:')
                && str_contains($request['text'], 'Christian, Daniel, Gustavo Mendes, Rodrigo Lima e no gol João.')
                && str_contains($request['text'], 'Se esse time ganhar eu vou liberar picanha para toda a QNF.');
        });

        @unlink($path);
    }

    public function test_lineup_accepts_dashed_arguments(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $player = $this->player();
        $this->draftedBlueTeam();

        $path = $this->postCommand('/lineup --blue --lula', $player)
            ->assertOk()
            ->json('audio_path');

        $this->assertIsString($path);
        $this->assertFileExists($path);
        @unlink($path);
    }

    public function test_lineup_without_arguments_returns_usage(): void
    {
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'Use /lineup {cor} {voz}. Cores: blue, yellow, green. Vozes: lula, bolsonaro, neymar.')
            ->assertJsonPath('audio_path', null);
    }

    public function test_lineup_missing_team_returns_an_error(): void
    {
        $this->enableFishAudio();
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup yellow bolsonaro', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'O time amarelo ainda não foi definido nesta rodada.')
            ->assertJsonPath('audio_path', null);

        Http::assertNothingSent();
    }

    public function test_lineup_is_unavailable_when_fish_audio_is_disabled(): void
    {
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup blue lula', $player)
            ->assertOk()
            ->assertJsonPath('reply', 'A narração de áudio está indisponível no momento.')
            ->assertJsonPath('audio_path', null);
    }

    public function test_lineup_has_a_global_one_hour_cooldown(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $first = $this->player();
        $second = $this->player();
        $this->draftedBlueTeam();

        $path = $this->postCommand('/lineup blue lula', $first)->assertOk()->json('audio_path');
        @unlink($path);

        $this->postCommand('/lineup green neymar', $second, ['message_id' => 'lineup-second'])
            ->assertOk()
            ->assertJsonPath('reply', 'A escalação já foi narrada recentemente. Tente de novo mais tarde.')
            ->assertJsonPath('audio_path', null);
    }

    public function test_admin_does_not_bypass_the_lineup_cooldown(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $player = $this->player();
        $admin = $this->admin();
        $this->draftedBlueTeam();

        $path = $this->postCommand('/lineup blue lula', $player)->assertOk()->json('audio_path');
        @unlink($path);

        $this->postCommand('/lineup blue bolsonaro', $admin, ['message_id' => 'lineup-admin'])
            ->assertOk()
            ->assertJsonPath('reply', 'A escalação já foi narrada recentemente. Tente de novo mais tarde.');
    }

    public function test_unlimited_phone_bypasses_the_lineup_cooldown(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $player = $this->player();
        $unlimited = $this->player(['phone' => '555199304836']);
        $this->draftedBlueTeam();

        $firstPath = $this->postCommand('/lineup blue lula', $player)->assertOk()->json('audio_path');
        @unlink($firstPath);

        $secondPath = $this->postCommand('/lineup blue neymar', $unlimited, [
            'message_id' => 'lineup-unlimited',
            'author_phone' => '+55 51 9930-4836',
            'author_id' => '555199304836@c.us',
        ])
            ->assertOk()
            ->json('audio_path');

        $this->assertIsString($secondPath);
        $this->assertFileExists($secondPath);
        @unlink($secondPath);
    }

    public function test_lineup_cooldown_expires_after_one_hour(): void
    {
        $this->enableFishAudio();
        $this->fakeFishAudio('ID3lineup-audio');
        $first = $this->player();
        $second = $this->player();
        $this->draftedBlueTeam();

        $path = $this->postCommand('/lineup blue lula', $first)->assertOk()->json('audio_path');
        @unlink($path);

        $this->travel(1)->hours();

        $secondPath = $this->postCommand('/lineup blue bolsonaro', $second, ['message_id' => 'lineup-later'])
            ->assertOk()
            ->json('audio_path');

        $this->assertIsString($secondPath);
        @unlink($secondPath);
    }

    public function test_phone_matcher_finds_users_by_local_and_formatted_numbers(): void
    {
        $player = $this->player(['phone' => '555199294672']);

        $this->assertTrue($player->is(PhoneNumber::findUser('+55 51 9929-4672')));
        $this->assertTrue($player->is(PhoneNumber::findUser('5199294672')));
        $this->assertTrue($player->is(PhoneNumber::findUser($player->phone.'@c.us')));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postCommand(string $body, ?User $sender, array $overrides = [])
    {
        return $this->withToken(self::SECRET)
            ->postJson(route('webhooks.whatsapp'), $this->payload($body, $sender, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $body, ?User $sender, array $overrides = []): array
    {
        $phone = $sender?->phone ?? '555199000000';

        return array_merge([
            'message_id' => 'msg-'.uniqid('', true),
            'chat_id' => self::GROUP_ID,
            'author_id' => $phone.'@c.us',
            'author_phone' => $phone,
            'from_me' => false,
            'body' => $body,
            'mentioned_phones' => [],
            'mentioned_ids' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function player(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'player',
            'position' => Position::WINGER,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function admin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'admin',
            'position' => Position::WINGER,
        ], $overrides));
    }

    private function openGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now()->subHour(),
            'round' => 20,
            'status' => GameStatus::OPEN,
        ]);
    }

    private function fullGameWithLinePlayers(int $count, GameStatus $status = GameStatus::FULL): Game
    {
        $game = $this->openGame();
        $game->update(['status' => $status]);

        User::factory()->count($count)->create(['position' => Position::WINGER])->each(function (User $user) use ($game): void {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        });

        return $game->fresh();
    }

    private function enableFishAudio(): void
    {
        config([
            'fish-audio.enabled' => true,
            'fish-audio.api_key' => 'test-fish-key',
            'fish-audio.model' => 's2.1-pro-free',
            'fish-audio.base_url' => 'https://api.fish.audio',
            'fish-audio.voices.lula' => 'voice-lula-id',
            'fish-audio.voices.bolsonaro' => 'voice-bolsonaro-id',
            'fish-audio.voices.neymar' => 'voice-neymar-id',
            'fish-audio.http.connect_timeout' => 10,
            'fish-audio.http.timeout' => 60,
            'fish-audio.http.retries' => 1,
            'fish-audio.http.retry_sleep_ms' => 0,
        ]);
    }

    private function fakeFishAudio(string $body = 'ID3lineup-audio'): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response($body, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);
    }

    private function draftedBlueTeam(): Game
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now()->subHour(),
            'round' => 20,
            'status' => GameStatus::DRAFTED,
        ]);

        $captain = User::factory()->create(['name' => 'Christian', 'position' => Position::FIXED]);
        $team = Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::BLUE,
            'captain_user_id' => $captain->id,
            'pick_order' => 1,
        ]);

        foreach ([
            ['name' => 'Daniel', 'position' => Position::WINGER],
            ['name' => 'Gustavo Mendes', 'position' => Position::WINGER],
            ['name' => 'Rodrigo Lima', 'position' => Position::PIVOT],
            ['name' => 'João', 'position' => Position::GOALKEEPER],
        ] as $index => $attributes) {
            $user = User::factory()->create($attributes);

            DraftPick::create([
                'game_id' => $game->id,
                'round' => intdiv($index, 3) + 1,
                'pick_in_round' => ($index % 3) + 1,
                'team_color' => $team->color,
                'picked_user_id' => $user->id,
                'picked_at' => now(),
            ]);
        }

        return $game->fresh(['teams.captain', 'draftPicks.pickedUser']);
    }
}
