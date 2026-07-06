<?php

namespace App\Exceptions\YouTube;

use RuntimeException;

class YouTubeVideoTooShortException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este vídeo é muito curto. Escolha uma música com pelo menos 1 minuto.');
    }
}
