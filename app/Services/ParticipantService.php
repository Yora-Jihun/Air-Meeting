<?php

namespace App\Services;

use App\Concerns\BroadcastsQuietly;
use App\Events\HostPromoted;
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

        $becomesHost = $isHost || $participant->is_host;

        $participant->fill([
            'display_name' => $displayName,
            'is_host' => $becomesHost,
            'joined_at' => now(),
            'last_seen_at' => now(),
            'left_at' => null,
        ]);
        $participant->meeting_id = $meeting->id;
        $participant->save();

        if ($becomesHost) {
            $this->demoteOtherHosts($meeting, $participant);
        }

        return $participant;
    }

    /**
     * Enforces "at most one active host" whenever a join grants host
     * status — either a fresh host-token match, or a rejoining row that
     * already carried it from before. Needed because promoteNextHost()
     * (below) can hand host status to a temporary successor while the
     * real host is away; if that real host later rejoins — even under a
     * different display name, which is a brand new participant row, still
     * carrying their session's host_token — nothing else would ever
     * demote the successor, leaving two hosts active at once.
     */
    private function demoteOtherHosts(Meeting $meeting, Participant $keep): void
    {
        $stillHost = $meeting->activeParticipants()
            ->where('id', '!=', $keep->id)
            ->where('is_host', true);

        if (! $stillHost->exists()) {
            return;
        }

        $stillHost->update(['is_host' => false]);

        $this->broadcastQuietly(new HostPromoted($meeting->uuid, $keep->participant_id));
    }

    public function leave(Meeting $meeting, string $participantId): void
    {
        $participant = $meeting->participants()
            ->where('participant_id', $participantId)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return;
        }

        $participant->update(['left_at' => now()]);

        // Only ever fires for the participant who was actually hosting —
        // everyone else leaving is the overwhelmingly common case and
        // shouldn't pay for a query that will almost always find nothing
        // to do.
        if ($participant->is_host) {
            $this->promoteNextHost($meeting);
        }
    }

    /**
     * Keeps a meeting from being permanently un-lockable/un-endable just
     * because its host's tab crashed or they closed it without a
     * successor — hands host status to whoever has been in the call
     * longest, the same heuristic Zoom/Meet use for automatic host
     * reassignment. A no-op if another host is already present (a
     * pre-existing co-host, or this participant wasn't the only host),
     * and a no-op if the meeting is now empty (nothing left to promote).
     */
    private function promoteNextHost(Meeting $meeting): void
    {
        if ($meeting->activeParticipants()->where('is_host', true)->exists()) {
            return;
        }

        $successor = $meeting->activeParticipants()->orderBy('joined_at')->first();

        if (! $successor) {
            return;
        }

        $successor->update(['is_host' => true]);

        $this->broadcastQuietly(new HostPromoted($meeting->uuid, $successor->participant_id));
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

    /**
     * Bumped by a client-side interval (see room.js) roughly every 15s
     * while a participant's tab is genuinely open. Silently a no-op for a
     * participant who has already left (kicked, or already reaped by
     * pruneStale()) — the client has no way to know that without a round
     * trip, so it just keeps pinging until its own leave()/redirect fires.
     */
    public function heartbeat(Meeting $meeting, string $participantId): void
    {
        $meeting->participants()
            ->where('participant_id', $participantId)
            ->whereNull('left_at')
            ->update(['last_seen_at' => now()]);
    }

    /**
     * Catches a tab that closed, refreshed into a crash, or lost its
     * connection without ever calling leave() — there are no Reverb
     * webhooks in this app to catch the presence channel vacating (see
     * MeetingCapacityTest's docblock and the removed beacon-on-pagehide
     * attempt, which falsely flagged every page refresh as a departure).
     * A participant only ever counts as stale here once they've gone
     * $staleAfterSeconds past their *own* last heartbeat — never merely for
     * lacking one, since a row with no last_seen_at yet is either mid-join
     * (join() sets it immediately) or predates this column, and either way
     * flagging it here would be indistinguishable from killing a real,
     * still-connected participant.
     */
    public function pruneStale(int $staleAfterSeconds = 45): int
    {
        // Routed through leave() one row at a time (rather than the single
        // bulk UPDATE this used to be) so a stale host still hands off to a
        // successor — the same rule an explicit Leave click already gets.
        // Stale rows are rare (a healthy tab heartbeats every ~15s against
        // a 45s threshold), so trading the bulk update for per-row
        // correctness costs little in practice.
        $stale = Participant::query()
            ->with('meeting')
            ->whereNull('left_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', now()->subSeconds($staleAfterSeconds))
            ->get();

        foreach ($stale as $participant) {
            $this->leave($participant->meeting, $participant->participant_id);
        }

        return $stale->count();
    }
}
