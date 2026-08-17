<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MeetingEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $meetingUuid,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("meeting.{$this->meetingUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'meeting.ended';
    }
}
