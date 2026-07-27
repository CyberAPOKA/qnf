<?php

namespace App\Instagram\Enums;

enum InstagramMediaType: string
{
    case Image = 'IMAGE';
    case Video = 'VIDEO';
    case Reels = 'REELS';
    case Stories = 'STORIES';
    case Carousel = 'CAROUSEL';
}
