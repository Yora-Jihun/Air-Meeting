<?php

namespace App\Services;

use App\Concerns\BroadcastsQuietly;
use App\Events\ParticipantKicked;
use App\Models\Meeting;
use App\Models\Participant;

/**
 * Owns participant membership rules for a meeting. Kept separate from
 * MeetingService because meeting lifecycle (create/expire/end) and
 * participant membership (join/leave/kick) change for different reasons
 * and are tested independently.
 */
class ParticipantService
{
    use BroadcastsQuietly;

    public function join(Meeting $meeting, string $participantId, string $displayName, bool $isHost = false): Participant
    {
        // Re-joining with the same browser-tab identity (e.g. a page refresh)
        // reactivates the existing row instead of creating a duplicate, so
        // the unique (meeting_id, participant_id) index never trips.
        $participant = $meeting->participants()->firstOrNew([
            'participant_id' => $participantId,
        ]);

        $participant->fill([
            'display_name' => $displayName,
            'is_host' => $isHost || $participant->is_host,
            'joined_at' => now(),
            'left_at' => null,
        ]);
        $participant->meeting_id = $meeting->id;
        $participant->save();

        return $participant;
    }

    public function leave(Meeting $meeting, string $participantId): void
    {
        $meeting->participants()
            ->where('participant_id', $participantId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }

    public function kick(Meeting $meeting, string $participantId): void
    {
        $this->leave($meeting, $participantId);

        $this->broadcastQuietly(new ParticipantKicked($meeting->uuid, $participantId), toOthers: true);
    }

    public function setMuted(Meeting $meeting, string $participantId, bool $muted): void
    {
        $meeting->participants()->where('participant_id', $participantId)->update(['is_muted' => $muted]);
    }

    public function setCameraOff(Meeting $meeting, string $participantId, bool $off): void
    {
        $meeting->participants()->where('participant_id', $participantId)->update(['is_camera_off' => $off]);
    }

    public function activeCount(Meeting $meeting): int
    {
        return $meeting->activeParticipants()->count();
    }
}
