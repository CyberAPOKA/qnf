<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecorderJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $gameId,
        public array $recorders,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("game.{$this->gameId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'RecorderJoined';
    }
}
