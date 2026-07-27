<?php

namespace App\Instagram\Enums;

enum InstagramPublicationType: string
{
    case DraftStory = 'draft-story';
    case WeeklyTeamsCarousel = 'weekly-teams-carousel';
    case WeeklyTeamStory = 'weekly-team-story';

    public function label(): string
    {
        return match ($this) {
            self::DraftStory => 'Story do draft',
            self::WeeklyTeamsCarousel => 'Carrossel times da semana',
            self::WeeklyTeamStory => 'Story time da semana',
        };
    }
}
