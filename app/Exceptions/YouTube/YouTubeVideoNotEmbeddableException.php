<?php

namespace App\Exceptions\YouTube;

use RuntimeException;

class YouTubeVideoNotEmbeddableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este vídeo não permite reprodução incorporada. Escolha outro resultado.');
    }
}
