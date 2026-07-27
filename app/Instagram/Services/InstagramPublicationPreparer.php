<?php

namespace App\Instagram\Services;

use App\Enums\TeamColor;
use App\Instagram\Enums\InstagramMediaType;
use App\Instagram\Enums\InstagramPublicationStatus;
use App\Instagram\Enums\InstagramPublicationType;
use App\Instagram\Exceptions\InstagramAssetException;
use App\Models\Game;
use App\Models\InstagramPublication;
use App\Models\InstagramPublicationItem;
use App\Models\User;
use App\Services\DraftService;
use App\Services\LineupsImageService;
use App\Services\WeekTeamImageService;
use App\Support\PublicStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstagramPublicationPreparer
{
    public function __construct(
        private readonly DraftService $draftService,
        private readonly LineupsImageService $lineupsImageService,
        private readonly WeekTeamImageService $weekTeamImageService,
        private readonly ScoringInstagramUsersResolver $scoringUsersResolver,
        private readonly InstagramCaptionBuilder $captionBuilder,
        private readonly InstagramFeedCarouselRenderer $feedRenderer,
        private readonly InstagramDraftStoryRenderer $draftStoryRenderer,
        private readonly InstagramStoryVideoService $storyVideoService,
        private readonly InstagramMusicResolver $musicResolver,
        private readonly InstagramAssetService $assetService,
        private readonly InstagramTagService $tagService,
    ) {}

    public function preparePayload(InstagramPublication $publication): void
    {
        $game = Game::query()
            ->with(['teams.captain', 'draftPicks.pickedUser', 'weekTeamMusics', 'gamePlayers'])
            ->findOrFail((int) $publication->trigger_id);

        $payload = match ($publication->publication_type) {
            InstagramPublicationType::DraftStory => $this->buildDraftPayload($game),
            InstagramPublicationType::WeeklyTeamsCarousel => $this->buildMatchCarouselPayload($game),
            InstagramPublicationType::WeeklyTeamStory => $this->buildMatchStoryPayload(
                $game,
                (string) ($publication->metadata['team_color'] ?? '')
            ),
        };

        $publication->update([
            'payload' => $payload,
            'status' => InstagramPublicationStatus::Preparing,
        ]);
    }

    public function renderAssets(InstagramPublication $publication): void
    {
        $publication->loadMissing('items');

        $game = Game::query()
            ->with(['teams.captain', 'draftPicks.pickedUser', 'weekTeamMusics', 'gamePlayers.user'])
            ->findOrFail((int) $publication->trigger_id);

        if (empty($publication->payload)) {
            $this->preparePayload($publication);
            $publication->refresh();
        }

        $dirRelative = $this->assetService->storePublicationDir($publication->uuid);
        $dirAbsolute = $this->assetService->absolutePath($dirRelative);
        File::ensureDirectoryExists($dirAbsolute);

        $publication->items()->delete();

        match ($publication->publication_type) {
            InstagramPublicationType::DraftStory => $this->renderDraftStory($publication, $game, $dirRelative, $dirAbsolute),
            InstagramPublicationType::WeeklyTeamsCarousel => $this->renderCarousel($publication, $game, $dirRelative, $dirAbsolute),
            InstagramPublicationType::WeeklyTeamStory => $this->renderTeamStory($publication, $game, $dirRelative, $dirAbsolute),
        };

        $publication->update(['status' => InstagramPublicationStatus::Rendering]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDraftPayload(Game $game): array
    {
        $teams = [];

        foreach ($this->draftService->teamsWithPlayers($game) as $color => $team) {
            $players = [];

            if (! empty($team['captain'])) {
                $players[] = [
                    'name' => $team['captain']['name'],
                    'is_captain' => true,
                ];
            }

            foreach ($team['players'] as $player) {
                $players[] = [
                    'name' => $player['name'],
                    'is_captain' => false,
                ];
            }

            $teams[] = [
                'color' => $color,
                'label' => TeamColor::from($color)->label(),
                'players' => $players,
            ];
        }

        return [
            'round' => $game->round,
            'date' => $game->date?->toDateString(),
            'teams' => $teams,
            'caption' => 'Draft finalizado — Rodada '.($game->round ?? $game->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMatchCarouselPayload(Game $game): array
    {
        $winnerColors = $this->weekTeamImageService->getWinnerColors($game);
        $winnerValues = array_map(fn (TeamColor $c) => $c->value, $winnerColors);
        $scoring = $this->scoringUsersResolver->resolveForGame($game);

        $teams = [];
        foreach (TeamColor::cases() as $color) {
            $team = $game->teams->firstWhere('color', $color);
            $points = in_array($color->value, $winnerValues, true) ? 1 : 0;
            $players = $this->playersForColor($game, $color);

            $teams[] = [
                'color' => $color->value,
                'label' => 'Time '.$color->label(),
                'goals' => $team?->score !== null ? (int) $team->score : null,
                'points' => $points,
                'players' => $players,
                'week_team_image' => $this->weekTeamImageForColor($game, $color),
                'music_path' => $this->musicRelativePathForColor($game, $color),
                'taggable_usernames' => $this->scoringUsersResolver->resolveForTeamColor($game, $color->value)['tagged'],
            ];
        }

        return [
            'round' => $game->round,
            'date' => $game->date?->toDateString(),
            'scores' => $game->teams
                ->mapWithKeys(fn ($t) => [$t->color->value => $t->score === null ? null : (int) $t->score])
                ->all(),
            'winner_colors' => $winnerValues,
            'week_team_images' => $game->week_team_images ?? [],
            'teams' => $teams,
            'taggable_usernames' => $scoring['tagged'],
            'tag_resolution' => [
                'ignored' => $scoring['ignored'],
                'rejected' => $scoring['rejected'],
            ],
            'caption' => $this->captionBuilder->buildWeeklyTeamsCaption(
                $game,
                $teams,
                $scoring['tagged']
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMatchStoryPayload(Game $game, string $colorValue): array
    {
        $color = TeamColor::from($colorValue);
        $team = $game->teams->firstWhere('color', $color);
        $winnerColors = $this->weekTeamImageService->getWinnerColors($game);
        $isWinner = collect($winnerColors)->contains(fn (TeamColor $c) => $c === $color);
        $scoring = $this->scoringUsersResolver->resolveForTeamColor($game, $color->value);
        $captain = $team?->captain;
        $music = $this->musicResolver->resolveForTeam($game, $color);

        return [
            'round' => $game->round,
            'date' => $game->date?->toDateString(),
            'team_color' => $color->value,
            'label' => 'Time '.$color->label(),
            'goals' => $team?->score !== null ? (int) $team->score : null,
            'points' => $isWinner ? 1 : 0,
            'players' => $this->playersForColor($game, $color),
            'week_team_image' => $this->weekTeamImageForColor($game, $color),
            'music' => [
                'path' => $music['path'],
                'source' => $music['source'],
                'title' => $music['title'],
            ],
            'taggable_usernames' => $scoring['tagged'],
            'tag_resolution' => [
                'ignored' => $scoring['ignored'],
                'rejected' => $scoring['rejected'],
            ],
            'caption' => 'Time da semana — '.$color->label().' | Rodada '.($game->round ?? $game->id),
        ];
    }

    private function renderDraftStory(
        InstagramPublication $publication,
        Game $game,
        string $dirRelative,
        string $dirAbsolute,
    ): void {
        $relative = $dirRelative.'/draft-story.jpg';
        $absolute = $dirAbsolute.'/draft-story.jpg';

        $lineupsRelative = rescue(
            fn () => $this->lineupsImageService->generate(
                $game,
                $this->draftService->buildTeamPlayerIdsForLineups($game)
            ),
            report: false
        );

        $sourceAbsolute = null;
        if (is_string($lineupsRelative) && $lineupsRelative !== '') {
            $sourceAbsolute = PublicStorage::localPath($lineupsRelative);
        }

        $this->draftStoryRenderer->render($game, $absolute, $sourceAbsolute);

        $this->createItem($publication, 0, InstagramMediaType::Image->value, $relative, [
            'kind' => 'draft_story',
        ]);
    }

    private function renderCarousel(
        InstagramPublication $publication,
        Game $game,
        string $dirRelative,
        string $dirAbsolute,
    ): void {
        $payload = $publication->payload ?? [];
        $teams = is_array($payload['teams'] ?? null) ? $payload['teams'] : [];
        $position = 0;

        $coverRelative = $dirRelative.'/cover.jpg';
        $this->feedRenderer->renderCover($game, $dirAbsolute.'/cover.jpg');
        $this->createItem($publication, $position++, InstagramMediaType::Image->value, $coverRelative, [
            'kind' => 'cover',
        ]);

        $maxItems = max(2, (int) config('instagram.limits.carousel_items', 10));

        foreach ($teams as $team) {
            if ($position >= $maxItems) {
                break;
            }

            $colorValue = (string) ($team['color'] ?? '');
            if ($colorValue === '') {
                continue;
            }

            $color = TeamColor::from($colorValue);
            $points = (int) ($team['points'] ?? 0);
            $players = is_array($team['players'] ?? null) ? $team['players'] : [];
            $file = 'team-'.$color->value.'.jpg';
            $relative = $dirRelative.'/'.$file;

            $this->feedRenderer->renderTeamCard(
                $game,
                $color,
                $points,
                $players,
                $dirAbsolute.'/'.$file,
                isset($team['goals']) ? (int) $team['goals'] : null,
            );

            $tags = $this->tagService->distributeTags(
                is_array($team['taggable_usernames'] ?? null) ? $team['taggable_usernames'] : []
            );

            $this->createItem($publication, $position++, InstagramMediaType::Image->value, $relative, [
                'kind' => 'team_card',
                'team_color' => $color->value,
                'points' => $points,
                'goals' => $team['goals'] ?? null,
                'taggable_usernames' => array_map(fn ($t) => $t->username, $tags),
                'user_tags' => array_map(fn ($t) => $t->toApiArray(), $tags),
            ]);
        }
    }

    private function renderTeamStory(
        InstagramPublication $publication,
        Game $game,
        string $dirRelative,
        string $dirAbsolute,
    ): void {
        $payload = $publication->payload ?? [];
        $colorValue = (string) ($payload['team_color'] ?? $publication->metadata['team_color'] ?? '');

        if ($colorValue === '') {
            throw new InstagramAssetException('Weekly team story missing team_color.');
        }

        $color = TeamColor::from($colorValue);
        $weekTeamRelative = $payload['week_team_image'] ?? $this->weekTeamImageForColor($game, $color);

        if (! is_string($weekTeamRelative) || $weekTeamRelative === '') {
            throw new InstagramAssetException("Week team image missing for color {$color->value}.");
        }

        $sourceAbsolute = PublicStorage::localPath($weekTeamRelative);
        if (! $sourceAbsolute || ! is_file($sourceAbsolute)) {
            throw new InstagramAssetException("Week team image file missing for color {$color->value}.");
        }

        $stillRelative = $dirRelative.'/story-'.$color->value.'.jpg';
        $stillAbsolute = $dirAbsolute.'/story-'.$color->value.'.jpg';
        $this->assetService->convertPngToJpeg($sourceAbsolute, $stillAbsolute);

        $music = $this->musicResolver->resolveForTeam($game, $color);

        $usernames = is_array($payload['taggable_usernames'] ?? null)
            ? $payload['taggable_usernames']
            : [];

        $videoRelative = $dirRelative.'/story-'.$color->value.'.mp4';
        $videoAbsolute = $dirAbsolute.'/story-'.$color->value.'.mp4';
        $duration = (int) config('instagram.story_duration_seconds', 15);

        $this->storyVideoService->build(
            $stillAbsolute,
            $music['path'] ?? null,
            $videoAbsolute,
            $duration,
        );

        $tags = $this->tagService->distributeTags($usernames);

        $this->createItem($publication, 0, InstagramMediaType::Video->value, $videoRelative, [
            'kind' => 'weekly_team_story',
            'team_color' => $color->value,
            'still_path' => $stillRelative,
            'music_source' => $music['source'] ?? 'none',
            'music_title' => $music['title'] ?? null,
            'taggable_usernames' => array_map(fn ($t) => $t->username, $tags),
            'user_tags' => array_map(fn ($t) => $t->toApiArray(), $tags),
            'tag_resolution' => $payload['tag_resolution'] ?? null,
        ]);
    }

    /**
     * @return list<array{name: string, instagram_username: ?string, is_captain: bool}>
     */
    private function playersForColor(Game $game, TeamColor $color): array
    {
        $team = $game->teams->firstWhere('color', $color);
        $players = [];

        if ($team?->captain) {
            $players[] = [
                'name' => (string) $team->captain->name,
                'instagram_username' => $team->captain->instagram_username ?? null,
                'is_captain' => true,
            ];
        }

        foreach ($game->draftPicks->where('team_color', $color)->sortBy('id') as $pick) {
            /** @var User|null $user */
            $user = $pick->pickedUser;
            if (! $user) {
                continue;
            }

            $players[] = [
                'name' => (string) $user->name,
                'instagram_username' => $user->instagram_username ?? null,
                'is_captain' => false,
            ];
        }

        return $players;
    }

    private function weekTeamImageForColor(Game $game, TeamColor $color): ?string
    {
        foreach ($game->week_team_images ?? [] as $path) {
            if (is_string($path) && str_contains($path, 'team-'.$color->value.'.png')) {
                return $path;
            }
        }

        $round = $game->round ?? $game->id;
        $guess = "week_team/{$round}/team-{$color->value}.png";

        return PublicStorage::localPath($guess) ? $guess : null;
    }

    private function musicRelativePathForColor(Game $game, TeamColor $color): ?string
    {
        $music = $game->weekTeamMusics->firstWhere('team_color', $color);

        return $music?->music_file_path;
    }

    private function createItem(
        InstagramPublication $publication,
        int $position,
        string $mediaType,
        string $relativePath,
        array $metadata = [],
    ): InstagramPublicationItem {
        $absolute = $this->assetService->absolutePath($relativePath);

        if (! is_file($absolute)) {
            throw new InstagramAssetException("Rendered asset missing: {$relativePath}");
        }

        return InstagramPublicationItem::create([
            'instagram_publication_id' => $publication->id,
            'position' => $position,
            'media_type' => $mediaType,
            'local_path' => $relativePath,
            'public_url' => $this->assetService->publicUrl($relativePath),
            'status' => InstagramPublicationStatus::Pending,
            'metadata' => array_merge($metadata, [
                'filename' => Str::afterLast($relativePath, '/'),
                'bytes' => filesize($absolute) ?: null,
            ]),
        ]);
    }
}
