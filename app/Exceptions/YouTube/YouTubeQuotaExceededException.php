<?php

namespace App\Exceptions\YouTube;

use RuntimeException;

class YouTubeQuotaExceededException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Limite da API do YouTube atingido. Tente novamente mais tarde.');
    }
}
