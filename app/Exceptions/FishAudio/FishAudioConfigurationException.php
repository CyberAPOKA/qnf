<?php

namespace App\Exceptions\FishAudio;

class FishAudioConfigurationException extends FishAudioException
{
    public function __construct(string $message)
    {
        parent::__construct($message, status: null, transient: false);
    }
}
