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
            'services.whatsapp.command_cooldown_seconds' => 3600,
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
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);
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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('user_id', $leaving->id)->first()->dropped_out);
        $this->assertNull(GamePlayer::where('game_id', $game->id)->where('user_id', $waiting->id)->first()->waitlist_at);
        $this->assertNotNull(GamePlayer::where('game_id', $game->id)->where('user_id', $waiting->id)->first()->joined_at);
    }

    public function test_commands_is_silent(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/commands', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);
    }

    public function test_comandos_is_an_alias_and_is_silent_for_admins(): void
    {
        $admin = $this->admin(['name' => 'Admin']);
        $this->openGame();

        $this->postCommand('/comandos', $admin)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);
    }

    public function test_play_and_jogar_share_the_same_cooldown(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/play', $player)->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/jogar', $player, ['message_id' => 'second'])
            ->assertOk()
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null);
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
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null);
    }

    public function test_play_cooldown_is_per_player(): void
    {
        $first = $this->player();
        $second = $this->player();
        $game = $this->openGame();

        $this->postCommand('/play', $first)->assertOk()->assertJsonPath('status', 'ok');
        $this->postCommand('/play', $second)->assertOk()->assertJsonPath('status', 'ok');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $first->id)->exists()
        );
        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $second->id)->exists()
        );
    }

    public function test_play_cooldown_expires_after_one_hour(): void
    {
        $player = $this->player();
        $this->openGame();

        $this->postCommand('/play', $player)->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/jogar', $player, ['message_id' => 'too-soon'])
            ->assertOk()
            ->assertJsonPath('status', 'rate_limited');

        $this->travel(1)->hours();

        $this->postCommand('/jogar', $player, ['message_id' => 'after-hour'])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);
    }

    public function test_commands_has_a_global_cooldown_for_the_whole_group(): void
    {
        $first = $this->player();
        $second = $this->player();
        $admin = $this->admin();
        $this->openGame();

        $this->postCommand('/commands', $first)->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/comandos', $second)
            ->assertOk()
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null);

        $this->postCommand('/comandos', $admin, ['message_id' => 'admin-commands'])
            ->assertOk()
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null);
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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);
    }

    public function test_regular_player_cannot_run_admin_commands(): void
    {
        $player = $this->player();
        $target = $this->player(['phone' => '555199555555']);
        $this->openGame();

        $this->postCommand('/add 555199555555', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ignored')
            ->assertJsonPath('reply', null);

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
            ->assertJsonPath('status', 'ignored')
            ->assertJsonPath('reply', null);

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
        $game = $this->fullGameWithLinePlayers(12, GameStatus::OPEN);

        $this->postCommand('/play', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->assertFalse(
            GamePlayer::where('game_id', $game->id)->where('user_id', $player->id)->exists()
        );
    }

    public function test_two_waitlist_joins_keep_order(): void
    {
        $first = $this->player(['name' => 'Primeiro']);
        $second = $this->player(['name' => 'Segundo']);
        $game = $this->fullGameWithLinePlayers(12);

        $this->postCommand('/play', $first)->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('reply', null);
        $this->postCommand('/play', $second)->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('reply', null);

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

    public function test_add_without_a_number_is_silent_and_does_not_change_the_game(): void
    {
        $admin = $this->admin();
        $this->openGame();

        $this->postCommand('/add', $admin)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->assertSame(0, GamePlayer::count());
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

    public function test_lineup_without_arguments_is_silent(): void
    {
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null)
            ->assertJsonPath('audio_path', null);
    }

    public function test_lineup_missing_team_is_silent(): void
    {
        $this->enableFishAudio();
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup yellow bolsonaro', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null)
            ->assertJsonPath('audio_path', null);

        Http::assertNothingSent();
    }

    public function test_lineup_is_silent_when_fish_audio_is_disabled(): void
    {
        $player = $this->player();
        $this->draftedBlueTeam();

        $this->postCommand('/lineup blue lula', $player)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null)
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
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null)
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
            ->assertJsonPath('status', 'rate_limited')
            ->assertJsonPath('reply', null);
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

    public function test_unlimited_phone_bypasses_play_and_commands_cooldown(): void
    {
        $player = $this->player();
        $unlimited = $this->player(['phone' => '555199304836']);
        $game = $this->openGame();

        $this->postCommand('/play', $player)->assertOk()->assertJsonPath('status', 'ok');
        $this->postCommand('/commands', $player, ['message_id' => 'commands-first'])->assertOk()->assertJsonPath('status', 'ok');

        $this->postCommand('/play', $unlimited, ['message_id' => 'unlimited-play'])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->postCommand('/jogar', $unlimited, ['message_id' => 'unlimited-play-again'])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->postCommand('/commands', $unlimited, ['message_id' => 'unlimited-commands'])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $unlimited->id)->exists()
        );
    }

    public function test_unlimited_phone_can_run_admin_commands(): void
    {
        $unlimited = $this->player(['phone' => '555199304836']);
        $target = $this->player(['phone' => '555199666666']);
        $game = $this->openGame();

        $this->postCommand('/add 555199666666', $unlimited, [
            'author_phone' => '+55 51 9930-4836',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->assertTrue(
            GamePlayer::where('game_id', $game->id)->where('user_id', $target->id)->exists()
        );
    }

    public function test_admin_add_has_no_timeout(): void
    {
        $admin = $this->admin();
        $first = $this->player(['phone' => '555199111111']);
        $second = $this->player(['phone' => '555199222222']);
        $game = $this->openGame();

        $this->postCommand('/add 555199111111', $admin)->assertOk()->assertJsonPath('status', 'ok');
        $this->postCommand('/add 555199222222', $admin, ['message_id' => 'admin-add-2'])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('reply', null);

        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('user_id', $first->id)->exists());
        $this->assertTrue(GamePlayer::where('game_id', $game->id)->where('user_id', $second->id)->exists());
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
