<?php

namespace Tests\Unit\WhatsApp;

use App\Enums\NarratorVoice;
use App\Enums\TeamColor;
use App\WhatsApp\Support\LineupArguments;
use PHPUnit\Framework\TestCase;

class LineupArgumentsTest extends TestCase
{
    public function test_it_parses_color_and_voice_in_either_order(): void
    {
        $parsed = LineupArguments::parse('blue lula');

        $this->assertSame(TeamColor::BLUE, $parsed?->color);
        $this->assertSame(NarratorVoice::LULA, $parsed?->voice);

        $reversed = LineupArguments::parse('neymar yellow');

        $this->assertSame(TeamColor::YELLOW, $reversed?->color);
        $this->assertSame(NarratorVoice::NEYMAR, $reversed?->voice);
    }

    public function test_it_accepts_dashed_flags_and_portuguese_colors(): void
    {
        $parsed = LineupArguments::parse('--green --bolsonaro');

        $this->assertSame(TeamColor::GREEN, $parsed?->color);
        $this->assertSame(NarratorVoice::BOLSONARO, $parsed?->voice);

        $portuguese = LineupArguments::parse('azul lula');

        $this->assertSame(TeamColor::BLUE, $portuguese?->color);
        $this->assertSame(NarratorVoice::LULA, $portuguese?->voice);
    }

    public function test_it_rejects_missing_or_invalid_arguments(): void
    {
        $this->assertNull(LineupArguments::parse(null));
        $this->assertNull(LineupArguments::parse(''));
        $this->assertNull(LineupArguments::parse('blue'));
        $this->assertNull(LineupArguments::parse('lula'));
        $this->assertNull(LineupArguments::parse('red lula'));
        $this->assertNull(LineupArguments::parse('blue messi'));
    }
}
