<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast whenever ParticipantService::promoteNextHost() hands host
 * status to a new participant — the meeting's original host left (or went
 * stale) and no other host was already present. Every client updates that
 * participant's "Host" badge live; the promoted participant's own client
 * additionally reloads, since host status also unlocks Blade-level UI
 * (@if($isHost) blocks in room.blade.php) that only a fresh mount picks up.
 */
class HostPromoted implements ShouldBroadcastNow
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
        return 'host.promoted';
    }

    public function broadcastWith(): array
    {
        return ['participant_id' => $this->participantId];
    }
}
