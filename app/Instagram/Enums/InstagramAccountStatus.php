<?php

namespace App\Instagram\Enums;

enum InstagramAccountStatus: string
{
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';
    case Disabled = 'disabled';
    case Error = 'error';
}
