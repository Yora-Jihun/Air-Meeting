<?php

namespace App\Services;

use App\Concerns\BroadcastsQuietly;
use App\Events\ChatMessageSent;
use App\Models\ChatMessage;
use App\Models\Meeting;
use Illuminate\Support\Collection;

/**
 * Owns meeting chat. Persisted, unlike the WebRTC signaling whispers (mic/
 * cam state, presentation) that never touch the database — a message
 * survives a refresh and reaches anyone who joins mid-call.
 */
class ChatService
{
    use BroadcastsQuietly;

    public function send(Meeting $meeting, string $participantId, string $displayName, string $message): ?ChatMessage
    {
        $message = trim($message);

        if ($message === '') {
            return null;
        }

        $chatMessage = $meeting->chatMessages()->create([
            'participant_id' => $participantId,
            'display_name' => $displayName,
            'message' => mb_substr($message, 0, 500),
        ]);

        $this->broadcastQuietly(new ChatMessageSent($meeting->uuid, $chatMessage));

        return $chatMessage;
    }

    /** @return Collection<int, ChatMessage> oldest first */
    public function recentFor(Meeting $meeting, int $limit = 200): Collection
    {
        return $meeting->chatMessages()->latest()->limit($limit)->get()->reverse()->values();
    }
}
