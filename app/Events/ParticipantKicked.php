<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ParticipantKicked implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $meetingUuid,
        public readonly string $participantId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("meeting.{$this->meetingUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'participant.kicked';
    }

    public function broadcastWith(): array
    {
        return ['participant_id' => $this->participantId];
    }
}
