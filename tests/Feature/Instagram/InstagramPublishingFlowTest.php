<?php

namespace Tests\Feature\Instagram;

use App\Enums\GameStatus;
use App\Enums\Position;
use App\Enums\TeamColor;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Jobs\ProcessInstagramPublicationJob;
use App\Instagram\Services\InstagramPublishingService;
use App\Instagram\Services\ScoringInstagramUsersResolver;
use App\Jobs\SendDraftFinishedWhatsApp;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\InstagramPublication;
use App\Models\Team;
use App\Models\User;
use App\Services\DraftService;
use App\Services\ScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstagramPublishingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'instagram.enabled' => true,
            'instagram.dry_run' => true,
            'instagram.access_token' => 'test-token',
            'instagram.user_id' => '123',
            'whatsapp.active' => false,
        ]);
    }

    public function test_disabled_integration_does_not_queue_publications(): void
    {
        config(['instagram.enabled' => false]);

        $game = $this->makeDraftedGame();

        $publication = app(InstagramPublishingService::class)->queueDraftStory($game);

        $this->assertNull($publication);
        $this->assertDatabaseCount('instagram_publications', 0);
    }

    public function test_draft_finalized_queues_single_story_publication(): void
    {
        Queue::fake();

        $game = $this->draftingGameWithTeams();
        $service = app(DraftService::class);

        $captainsByColor = [];
        foreach ($game->teams as $team) {
            $captainsByColor[$team->color->value] = $team->captain_user_id;
        }

        $linePlayers = User::factory()->count(9)->create(['position' => Position::WINGER]);
        $goalkeepers = User::factory()->count(3)->create(['position' => Position::GOALKEEPER]);
        $players = $linePlayers->concat($goalkeepers);

        foreach ($players as $player) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $player->id,
                'joined_at' => now(),
            ]);
        }

        foreach ($players as $player) {
            $game->refresh();

            if ($game->status === GameStatus::DRAFTED) {
                break;
            }

            $turnColor = $service->currentTurnColor($game);
            $this->assertNotNull($turnColor);
            $captainId = $captainsByColor[$turnColor->value];
            $service->makePick($game, $player->id, $captainId);
        }

        $game->refresh();
        $this->assertSame(GameStatus::DRAFTED, $game->status);
        $this->assertDatabaseCount('instagram_publications', 1);

        $publication = InstagramPublication::first();
        $this->assertSame(InstagramPublicationType::DraftStory, $publication->publication_type);
        $this->assertSame('draft-finalized:'.$game->id.':v1:draft-story', $publication->idempotency_key);

        Queue::assertPushed(ProcessInstagramPublicationJob::class, 1);
        Queue::assertPushed(SendDraftFinishedWhatsApp::class);
    }

    public function test_repeated_queue_does_not_duplicate_publication(): void
    {
        Queue::fake();
        $game = $this->makeDraftedGame();
        $service = app(InstagramPublishingService::class);

        $first = $service->queueDraftStory($game);
        $second = $service->queueDraftStory($game);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('instagram_publications', 1);
    }

    public function test_scoring_resolver_uses_domain_points_only(): void
    {
        $game = $this->makeDraftedGame();
        $winner = User::factory()->create(['instagram_username' => 'winner_one']);
        $loser = User::factory()->create(['instagram_username' => 'loser_one']);
        $noIg = User::factory()->create(['instagram_username' => null]);

        GamePlayer::create(['game_id' => $game->id, 'user_id' => $winner->id, 'joined_at' => now(), 'points' => 1]);
        GamePlayer::create(['game_id' => $game->id, 'user_id' => $loser->id, 'joined_at' => now(), 'points' => 0]);
        GamePlayer::create(['game_id' => $game->id, 'user_id' => $noIg->id, 'joined_at' => now(), 'points' => 1]);

        Team::create([
            'game_id' => $game->id,
            'color' => TeamColor::GREEN,
            'captain_user_id' => $winner->id,
            'score' => 5,
            'pick_order' => 1,
        ]);

        $result = app(ScoringInstagramUsersResolver::class)->resolveForGame($game);

        $this->assertSame(['winner_one'], $result['tagged']);
        $this->assertCount(1, $result['ignored']);
        $this->assertSame('missing_username', $result['ignored'][0]['reason']);
        $this->assertNotContains('loser_one', $result['tagged']);
    }

    public function test_match_result_queues_carousel_and_stories(): void
    {
        Queue::fake([ProcessInstagramPublicationJob::class]);

        $game = $this->makeScoredGameReadyForWeekTeam();
        $publications = app(InstagramPublishingService::class)->queueMatchResultPublications($game);

        $types = collect($publications)->map(fn ($p) => $p->publication_type->value)->all();
        $this->assertContains(InstagramPublicationType::WeeklyTeamsCarousel->value, $types);
        $this->assertContains(InstagramPublicationType::WeeklyTeamStory->value, $types);
        $this->assertGreaterThanOrEqual(2, count($publications));
    }

    public function test_profile_instagram_update_normalizes_username(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.update-instagram'), [
                'instagram_username' => 'https://www.instagram.com/My.User_Name/',
            ])
            ->assertRedirect();

        $this->assertSame('my.user_name', $user->fresh()->instagram_username);
    }

    public function test_instagram_failure_does_not_rollback_scores(): void
    {
        $game = $this->makeDraftedGameWithTeams();
        app(ScoringService::class)->saveScores($game, [
            'green' => 3,
            'yellow' => 1,
            'blue' => 0,
        ], force: true);

        $game->refresh();
        $this->assertSame(GameStatus::DONE, $game->status);
        $this->assertSame(3, (int) $game->teams->firstWhere('color', TeamColor::GREEN)->score);
    }

    private function makeDraftedGame(): Game
    {
        return Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTED,
            'round' => 7,
        ]);
    }

    private function makeDraftedGameWithTeams(): Game
    {
        $game = $this->makeDraftedGame();
        $captains = User::factory()->count(3)->create();

        foreach ([TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE] as $i => $color) {
            Team::create([
                'game_id' => $game->id,
                'color' => $color,
                'captain_user_id' => $captains[$i]->id,
                'pick_order' => $i + 1,
            ]);
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $captains[$i]->id,
                'joined_at' => now(),
            ]);
        }

        return $game->fresh(['teams']);
    }

    private function makeScoredGameReadyForWeekTeam(): Game
    {
        $game = $this->makeDraftedGameWithTeams();
        app(ScoringService::class)->saveScores($game, [
            'green' => 4,
            'yellow' => 2,
            'blue' => 1,
        ], force: true);

        return $game->fresh(['teams.captain', 'draftPicks.pickedUser', 'weekTeamMusics']);
    }

    private function draftingGameWithTeams(): Game
    {
        $captains = User::factory()->count(3)->create();
        $game = Game::create([
            'date' => now()->toDateString(),
            'opens_at' => now(),
            'status' => GameStatus::DRAFTING,
            'round' => 10,
        ]);

        foreach ($captains as $captain) {
            GamePlayer::create([
                'game_id' => $game->id,
                'user_id' => $captain->id,
                'joined_at' => now(),
            ]);
        }

        foreach ([TeamColor::GREEN, TeamColor::YELLOW, TeamColor::BLUE] as $i => $color) {
            Team::create([
                'game_id' => $game->id,
                'color' => $color,
                'captain_user_id' => $captains[$i]->id,
                'pick_order' => $i + 1,
            ]);
        }

        return $game->fresh(['teams']);
    }
}
