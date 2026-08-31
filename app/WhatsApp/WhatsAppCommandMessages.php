<?php

namespace App\WhatsApp;

use App\Enums\TeamColor;
use App\Support\PersonName;
use App\WhatsApp\Enums\WhatsAppCommandType;

/**
 * Text templates for WhatsApp commands.
 *
 * These strings are not sent back to the group: commands run silently
 * so the chat is not flooded. Only /lineup delivers audio.
 */
class WhatsAppCommandMessages
{
    public static function joined(string $name): string
    {
        return self::withName($name, 'você entrou na partida.');
    }

    public static function waitlisted(string $name, int $position): string
    {
        return self::withName($name, "você está na fila de espera ({$position}º).");
    }

    public static function alreadyJoined(string $name): string
    {
        return self::withName($name, 'você já está inscrito nesta partida.');
    }

    public static function alreadyWaitlisted(string $name, ?int $position): string
    {
        $suffix = $position ? " ({$position}º)" : '';

        return self::withName($name, "você já está na fila de espera{$suffix}.");
    }

    public static function quit(string $name, ?string $promotedName = null): string
    {
        $message = self::withName($name, 'você desistiu da partida.');

        if ($promotedName) {
            $message .= " {$promotedName} subiu da fila.";
        }

        return $message;
    }

    public static function leftWaitlist(string $name): string
    {
        return self::withName($name, 'você saiu da fila de espera.');
    }

    public static function notParticipating(string $name): string
    {
        return self::withName($name, 'você não está na partida nem na fila.');
    }

    public static function unknownPlayer(): string
    {
        return 'Este número não está cadastrado no QNF.';
    }

    public static function cooldown(): string
    {
        return 'Aguarde um pouco antes de enviar este comando de novo.';
    }

    public static function commandsCooldown(): string
    {
        return 'Os comandos já foram listados recentemente. Tente de novo mais tarde.';
    }

    public static function unauthorized(): string
    {
        return 'Você não tem permissão para este comando.';
    }

    public static function invalidNumber(): string
    {
        return 'Informe um número válido. Ex.: /add 51999999999';
    }

    public static function playerNotFound(): string
    {
        return 'Não encontrei um jogador com esse número.';
    }

    public static function added(string $adminName, string $playerName, bool $waitlisted, ?int $position = null): string
    {
        if ($waitlisted) {
            $place = $position ? " ({$position}º)" : '';

            return self::withName($adminName, "{$playerName} entrou na fila de espera{$place}.");
        }

        return self::withName($adminName, "{$playerName} foi adicionado à partida.");
    }

    public static function removed(string $adminName, string $playerName, bool $fromWaitlist, ?string $promotedName = null): string
    {
        $message = $fromWaitlist
            ? self::withName($adminName, "{$playerName} foi removido da fila de espera.")
            : self::withName($adminName, "{$playerName} foi removido da partida.");

        if ($promotedName) {
            $message .= " {$promotedName} subiu da fila.";
        }

        return $message;
    }

    public static function gameUnavailable(): string
    {
        return 'A partida não está disponível no momento.';
    }

    public static function help(bool $isAdmin): string
    {
        $lines = [
            'Comandos QNF:',
            '/jogar ou /play — entrar na partida ou na fila',
            '/desistir ou /quit — desistir da partida ou sair da fila',
            '/comandos ou /commands — mostrar os comandos disponíveis',
            '/lineup {cor} {voz} — narrar a escalação em áudio',
        ];

        if ($isAdmin) {
            $lines[] = '/add {número} — adicionar jogador';
            $lines[] = '/remove {número} — remover jogador';
        }

        return implode("\n", $lines);
    }

    public static function firstName(?string $fullName): string
    {
        return PersonName::split($fullName)['first_name'] ?? 'Jogador';
    }

    public static function lineupUsage(): string
    {
        return 'Use /lineup {cor} {voz}. Cores: blue, yellow, green. Vozes: lula, bolsonaro, neymar.';
    }

    public static function lineupUnavailable(): string
    {
        return 'A narração de áudio está indisponível no momento.';
    }

    public static function lineupTeamMissing(TeamColor $color): string
    {
        return 'O time '.mb_strtolower($color->label()).' ainda não foi definido nesta rodada.';
    }

    public static function lineupTeamEmpty(TeamColor $color): string
    {
        return 'O time '.mb_strtolower($color->label()).' ainda não tem jogadores.';
    }

    public static function lineupFailed(): string
    {
        return 'Não consegui gerar o áudio da escalação. Tente de novo mais tarde.';
    }

    public static function lineupCooldown(): string
    {
        return 'A escalação já foi narrada recentemente. Tente de novo mais tarde.';
    }

    public static function rateLimited(WhatsAppCommandType $type): string
    {
        return match ($type) {
            WhatsAppCommandType::Commands => self::commandsCooldown(),
            WhatsAppCommandType::Lineup => self::lineupCooldown(),
            default => self::cooldown(),
        };
    }

    private static function withName(string $name, string $message): string
    {
        return "{$name}, {$message}";
    }
}
