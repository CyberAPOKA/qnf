<?php

namespace Tests\Unit;

use App\Support\PersonName;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    public function test_it_splits_first_and_last_name(): void
    {
        $this->assertSame(
            ['first_name' => 'Joao', 'last_name' => 'Silva Santos'],
            PersonName::split('Joao Silva Santos'),
        );
    }

    public function test_it_returns_only_first_name_when_there_is_no_surname(): void
    {
        $this->assertSame(
            ['first_name' => 'Pelé', 'last_name' => null],
            PersonName::split('Pelé'),
        );
    }

    public function test_it_handles_blank_and_extra_spaces(): void
    {
        $this->assertSame(
            ['first_name' => null, 'last_name' => null],
            PersonName::split('   '),
        );

        $this->assertSame(
            ['first_name' => 'Maria', 'last_name' => 'Souza'],
            PersonName::split('  Maria   Souza  '),
        );
    }
}
