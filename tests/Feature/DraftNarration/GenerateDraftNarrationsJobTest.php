<?php

namespace Tests\Feature\DraftNarration;

use App\Enums\DraftNarrationStatus;
use App\Enums\GameStatus;
use App\Enums\NarratorVoice;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Jobs\GenerateDraftNarrationsJob;
use App\Models\DraftNarration;
use App\Models\DraftPick;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftNarration\DraftNarrationService;
use App\Services\DraftService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GenerateDraftNarrationsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'fish-audio.enabled' => true,
            'fish-audio.api_key' => 'test-fish-key',
            'fish-audio.model' => 's2.1-pro-free',
            'fish-audio.base_url' => 'https://api.fish.audio',
            'fish-audio.narrator' => 'lula',
            'fish-audio.voices.lula' => 'voice-lula-id',
            'fish-audio.voices.bolsonaro' => 'voice-bolsonaro-id',
            'fish-audio.disk' => 'local',
            'fish-audio.http.retries' => 3,
            'fish-audio.http.retry_sleep_ms' => 0,
            'whatsapp.active' => false,
        ]);
    }

    public function test_job_is_dispatched_only_after_the_draft_is_finished(): void
    {
        Queue::fake();

        $game = $this->draftingGameReadyForPicks();
        $service = app(DraftService::class);
        $firstPlayer = $game->players->first(fn (User $user) => ! $game->teams->pluck('captain_user_id')->contains($user->id));

        $service->makePick($game, $firstPlayer->id, $game->teams->firstWhere('color', TeamColor::GREEN)->captain_user_id);
        $game->refresh();

        $this->assertSame(GameStatus::DRAFTING, $game->status);
        Queue::assertNotPushed(GenerateDraftNarrationsJob::class);

        $this->finishDraft($game);
        $game->refresh();

        $this->assertSame(GameStatus::DRAFTED, $game->status);
        Queue::assertPushed(GenerateDraftNarrationsJob::class, 1);
        Queue::assertPushed(GenerateDraftNarrationsJob::class, fn (GenerateDraftNarrationsJob $job) => $job->gameId === $game->id);
    }

    public function test_job_is_not_dispatched_when_fish_audio_is_disabled(): void
    {
        config(['fish-audio.enabled' => false]);
        Queue::fake();

        $game = $this->draftingGameReadyForPicks();
        $this->finishDraft($game);

        Queue::assertNotPushed(GenerateDraftNarrationsJob::class);
    }

    public function test_job_does_nothing_when_feature_flag_is_off(): void
    {
        config(['fish-audio.enabled' => false]);
        Http::fake();
        $whatsApp = $this->mock(WhatsAppService::class);
        $whatsApp->shouldNotReceive('sendAudioToGroup');

        $game = $this->makeDraftedGame();

        (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));

        Http::assertNothingSent();
        $this->assertDatabaseCount('draft_narrations', 0);
        $this->assertSame(GameStatus::DRAFTED, $game->fresh()->status);
    }

    public function test_it_generates_one_audio_per_team_in_draft_order(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $captions = [];
        $this->mock(WhatsAppService::class, function ($mock) use (&$captions) {
            $mock->shouldReceive('sendAudioToGroup')
                ->times(3)
                ->andReturnUsing(function (string $path, string $caption) use (&$captions) {
                    $captions[] = $caption;

                    return true;
                });
        });

        $game = $this->makeDraftedGame();

        (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));

        $this->assertSame(['Time Verde', 'Time Amarelo', 'Time Azul'], $captions);
        $this->assertDatabaseCount('draft_narrations', 3);

        $teams = $game->teams()->orderBy('id')->get()->keyBy(fn (Team $team) => $team->color->value);

        foreach (TeamColor::cases() as $color) {
            $team = $teams->get($color->value);
            $path = "drafts/{$game->id}/narrations/{$team->id}.mp3";

            Storage::disk('local')->assertExists($path);
            $this->assertDatabaseHas('draft_narrations', [
                'game_id' => $game->id,
                'team_id' => $team->id,
                'status' => DraftNarrationStatus::SENT->value,
            ]);
            $this->assertNotNull(
                DraftNarration::query()->where('game_id', $game->id)->where('team_id', $team->id)->value('whatsapp_sent_at')
            );
        }

        Http::assertSentCount(3);
    }

    public function test_reprocessing_does_not_send_duplicates(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')->times(3)->andReturn(true);
        });

        $game = $this->makeDraftedGame();
        $job = new GenerateDraftNarrationsJob($game->id);

        $job->handle(app(DraftNarrationService::class));
        $job->handle(app(DraftNarrationService::class));

        Http::assertSentCount(3);
        $this->assertDatabaseCount('draft_narrations', 3);
        $this->assertSame(3, DraftNarration::query()->whereNotNull('whatsapp_sent_at')->count());
    }

    public function test_whatsapp_retry_reuses_existing_audio(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $game = $this->makeDraftedGame();
        $team = $game->teams->firstWhere('color', TeamColor::GREEN);
        $path = "drafts/{$game->id}/narrations/{$team->id}.mp3";

        Storage::disk('local')->put($path, 'already-generated');

        DraftNarration::create([
            'game_id' => $game->id,
            'team_id' => $team->id,
            'version' => 1,
            'voice' => NarratorVoice::LULA,
            'text' => 'Convocação do time verde.',
            'path' => $path,
            'status' => DraftNarrationStatus::GENERATED,
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')->times(3)->andReturn(true);
        });

        (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));

        $this->assertSame('already-generated', Storage::disk('local')->get($path));
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.fish.audio/v1/tts'
                && ! str_contains((string) $request['text'], 'time verde');
        });
        $this->assertSame(2, collect(Http::recorded())->count());
    }

    public function test_fish_audio_error_does_not_undo_the_draft(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response(['message' => 'Server error'], 500),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldNotReceive('sendAudioToGroup');
        });

        $game = $this->makeDraftedGame();

        (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));

        $this->assertSame(GameStatus::DRAFTED, $game->fresh()->status);
        $this->assertSame(3, DraftNarration::query()->where('status', DraftNarrationStatus::FAILED)->count());
        $this->assertSame(0, DraftNarration::query()->whereNotNull('whatsapp_sent_at')->count());
    }

    public function test_whatsapp_error_does_not_regenerate_audio(): void
    {
        Http::fake([
            'https://api.fish.audio/v1/tts' => Http::response('ID3fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')->once()->andReturn(false);
        });

        $game = $this->makeDraftedGame();

        try {
            (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));
            $this->fail('Expected WhatsApp retry exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('WhatsApp', $e->getMessage());
        }

        $this->assertSame(GameStatus::DRAFTED, $game->fresh()->status);
        $this->assertSame(3, Http::recorded()->count());

        $generated = DraftNarration::query()->where('game_id', $game->id)->whereNotNull('path')->get();
        $this->assertCount(3, $generated);

        foreach ($generated as $narration) {
            Storage::disk('local')->assertExists($narration->path);
        }

        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendAudioToGroup')->times(3)->andReturn(true);
        });

        (new GenerateDraftNarrationsJob($game->id))->handle(app(DraftNarrationService::class));

        $this->assertSame(3, Http::recorded()->count());
        $this->assertSame(3, DraftNarration::query()->whereNotNull('whatsapp_sent_at')->count());
    }

    private function finishDraft(Game $game): void
    {
        $service = app(DraftService::class);
        $captainsByColor = $game->teams->mapWithKeys(
            fn (Team $team) => [$team->color->value => $team->captain_user_id]
        );

        $picked = $game->draftPicks()->pluck('picked_user_id');
        $remaining = $game->players
            ->filter(fn (User $user) => ! $captainsByColor->contains($user->id) && ! $picked->contains($user->id))
            ->values();

        foreach ($remaining as $player) {
            $game->refresh();

            if ($game->status === GameStatus::DRAFTED) {
                break;
            }

            $turnColor = $service->currentTurnColor($game);
            $service->makePick($game, $player->id, $captainsByColor[$turnColor->value]);
        }
    }

    private function draftingGameReadyForPicks(): Game
    {
        $captains = User::factory()->count(3)->create(['position' => Position::FIXED]);
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTING,
            'round' => 12,
        ]);

        foreach ($captains as $index => $captain) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $captain->id,
                'joined_at' => now(),
            ]);

            Team::create([
                'game_id' => $game->id,
                'color' => TeamColor::cases()[$index],
                'captain_user_id' => $captain->id,
                'pick_order' => $index + 1,
            ]);
        }

        $linePlayers = User::factory()->count(9)->create(['position' => Position::WINGER]);
        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER]);

        foreach ($linePlayers->concat($goalkeepers) as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        return $game->fresh(['teams', 'players', 'draftPicks']);
    }

    private function makeDraftedGame(): Game
    {
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
            'round' => 12,
        ]);

        foreach (TeamColor::cases() as $index => $color) {
            $captain = User::factory()->create([
                'name' => 'Capitão '.$color->label(),
                'position' => Position::FIXED,
            ]);
            $goalkeeper = User::factory()->create([
                'name' => 'Goleiro '.$color->label(),
                'position' => Position::GOALKEEPER,
            ]);
            $winger = User::factory()->create([
                'name' => 'Ala '.$color->label(),
                'position' => Position::WINGER,
            ]);
            $pivot = User::factory()->create([
                'name' => 'Pivô '.$color->label(),
                'position' => Position::PIVOT,
            ]);

            GamePlayer::create(['game_id' => $game->id, 'user_id' => $captain->id, 'joined_at' => now()]);
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $goalkeeper->id, 'joined_at' => now()]);
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $winger->id, 'joined_at' => now()]);
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $pivot->id, 'joined_at' => now()]);

            Team::create([
                'game_id' => $game->id,
                'color' => $color,
                'captain_user_id' => $captain->id,
                'pick_order' => $index + 1,
            ]);

            foreach ([$goalkeeper, $winger, $pivot] as $offset => $player) {
                DraftPick::create([
                    'game_id' => $game->id,
                    'round' => 1,
                    'pick_in_round' => $offset + 1,
                    'team_color' => $color,
                    'picked_user_id' => $player->id,
                    'picked_at' => now(),
                ]);
            }
        }

        return $game->fresh(['teams.captain', 'draftPicks.pickedUser']);
    }
}
