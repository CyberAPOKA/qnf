<?php

namespace Tests\Unit\WhatsApp;

use App\Support\PhoneNumber;
use App\WhatsApp\Data\IncomingWhatsAppMessage;
use App\WhatsApp\Enums\WhatsAppCommandType;
use App\WhatsApp\WhatsAppCommandParser;
use PHPUnit\Framework\TestCase;

class WhatsAppCommandParserTest extends TestCase
{
    private WhatsAppCommandParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WhatsAppCommandParser;
    }

    public function test_it_parses_player_aliases(): void
    {
        $this->assertSame(WhatsAppCommandType::Play, $this->parser->parse($this->message('/play'))?->type);
        $this->assertSame(WhatsAppCommandType::Play, $this->parser->parse($this->message('/JOGAR'))?->type);
        $this->assertSame(WhatsAppCommandType::Quit, $this->parser->parse($this->message('/desistir'))?->type);
        $this->assertSame(WhatsAppCommandType::Quit, $this->parser->parse($this->message('/quit'))?->type);
        $this->assertSame(WhatsAppCommandType::Commands, $this->parser->parse($this->message('/comandos'))?->type);
        $this->assertSame(WhatsAppCommandType::Commands, $this->parser->parse($this->message('/commands'))?->type);
    }

    public function test_it_parses_admin_commands_with_arguments(): void
    {
        $add = $this->parser->parse($this->message('/add +55 51 99999-9999'));

        $this->assertSame(WhatsAppCommandType::Add, $add?->type);
        $this->assertSame('+55 51 99999-9999', $add?->argument);

        $remove = $this->parser->parse($this->message('/remove 51999999999'));

        $this->assertSame(WhatsAppCommandType::Remove, $remove?->type);
        $this->assertSame('51999999999', $remove?->argument);
    }

    public function test_it_ignores_plain_group_messages(): void
    {
        $this->assertNull($this->parser->parse($this->message('bora jogar')));
        $this->assertNull($this->parser->parse($this->message('/unknown')));
        $this->assertNull($this->parser->parse($this->message('')));
    }

    public function test_phone_digits_strip_whatsapp_ids_and_formatting(): void
    {
        $jid = '555199294672'.'@c.us';

        $this->assertSame('555199294672', PhoneNumber::digits($jid));
        $this->assertSame('555199294672', PhoneNumber::digits('+55 51 9929-4672'));
        $this->assertSame('99294672', PhoneNumber::lastEight('5199294672'));
    }

    private function message(string $body): IncomingWhatsAppMessage
    {
        return new IncomingWhatsAppMessage(
            messageId: 'msg-1',
            chatId: 'laura.c@example.net',
            authorId: 'xavier.y@example.org',
            authorPhone: '555199294672',
            fromMe: false,
            body: $body,
        );
    }
}
