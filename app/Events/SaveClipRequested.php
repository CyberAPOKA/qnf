<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaveClipRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $gameId,
        public string $saveRequestUuid,
        public int $saveRequestId,
        public string $triggeredByName,
        public int $expectedRecorders,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("game.{$this->gameId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SaveClipRequested';
    }
}
