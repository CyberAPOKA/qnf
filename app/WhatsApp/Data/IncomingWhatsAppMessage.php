<?php

namespace App\WhatsApp\Data;

use Illuminate\Http\Request;

readonly class IncomingWhatsAppMessage
{
    /**
     * @param  list<string>  $mentionedPhones
     * @param  list<string>  $mentionedIds
     */
    public function __construct(
        public string $messageId,
        public string $chatId,
        public string $authorId,
        public ?string $authorPhone,
        public bool $fromMe,
        public string $body,
        public array $mentionedPhones = [],
        public array $mentionedIds = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            messageId: (string) $request->input('message_id', ''),
            chatId: (string) $request->input('chat_id', ''),
            authorId: (string) $request->input('author_id', ''),
            authorPhone: self::nullableString($request->input('author_phone')),
            fromMe: $request->boolean('from_me'),
            body: (string) $request->input('body', ''),
            mentionedPhones: self::stringList($request->input('mentioned_phones', [])),
            mentionedIds: self::stringList($request->input('mentioned_ids', [])),
        );
    }

    /**
     * @return list<string>
     */
    public function mentionPhones(): array
    {
        $phones = $this->mentionedPhones;

        if ($phones === [] && $this->mentionedIds !== []) {
            $phones = $this->mentionedIds;
        }

        return array_values(array_filter($phones));
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($item) => is_string($item) && $item !== ''));
    }
}
