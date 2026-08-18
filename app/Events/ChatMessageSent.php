<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Deliberately not broadcast with toOthers(): unlike ParticipantKicked
 * (where the acting host's own tab has no use for the event), the sender
 * of a chat message needs this same broadcast to render their own message
 * too — there's no separate optimistic local echo, so this is the only
 * path that ever appends a sent message to the sender's own chat list.
 */
class ChatMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $meetingUuid,
        public readonly ChatMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("meeting.{$this->meetingUuid}")];
    }

    public function broadcastAs(): string
    {
        return 'chat.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'participant_id' => $this->message->participant_id,
            'name' => $this->message->display_name,
            'message' => $this->message->message,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }
}
