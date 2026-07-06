<?php

namespace App\Exceptions\YouTube;

use RuntimeException;

class YouTubeVideoNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Vídeo não encontrado.');
    }
}
