<?php

namespace App\WhatsApp\Enums;

enum WhatsAppCommandType: string
{
    case Play = 'play';
    case Quit = 'quit';
    case Commands = 'commands';
    case Add = 'add';
    case Remove = 'remove';
    case Lineup = 'lineup';
    case Ping = 'ping';

    public function isAdmin(): bool
    {
        return in_array($this, [self::Add, self::Remove], true);
    }

    public function rateLimitBucket(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function aliases(self $type): array
    {
        return match ($type) {
            self::Play => ['play', 'jogar'],
            self::Quit => ['desistir', 'quit'],
            self::Commands => ['commands', 'comandos'],
            self::Add => ['add'],
            self::Remove => ['remove'],
            self::Lineup => ['lineup'],
            self::Ping => ['ping'],
        };
    }

    public static function fromAlias(string $alias): ?self
    {
        $normalized = mb_strtolower(ltrim($alias, '/'));

        foreach (self::cases() as $type) {
            if (in_array($normalized, self::aliases($type), true)) {
                return $type;
            }
        }

        return null;
    }
}
