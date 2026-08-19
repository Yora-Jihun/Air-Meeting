<?php

namespace App\Livewire\Meeting;

use App\Models\Meeting;
use App\Models\Participant;
use App\Services\ChatService;
use App\Services\MeetingService;
use App\Services\ParticipantService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class Room extends Component
{
    public Meeting $meeting;

    public string $participantId;

    public ?int $participantRecordId = null;

    public bool $hasJoined = false;

    public bool $isHost = false;

    public int $participantCount = 0;

    public ?string $joinedAt = null;

    /** Chat history so far, handed to the Alpine component once at load —
     * everything after that arrives live over the broadcast channel. */
    public array $initialMessages = [];

    public function mount(Meeting $meeting, ChatService $chat): void
    {
        $this->meeting = $meeting;
        $this->isHost = $meeting->isHost(session("meeting.{$meeting->uuid}.host_token"));
        $this->initialMessages = $chat->recentFor($meeting)->map(fn ($message) => [
            'id' => $message->id,
            'participant_id' => $message->participant_id,
            'name' => $message->display_name,
            'message' => $message->message,
            'created_at' => $message->created_at->toIso8601String(),
        ])->all();

        $sessionParticipantId = session("meeting.{$meeting->uuid}.participant_id");

        $existing = $sessionParticipantId
            ? $meeting->participants()->active()->where('participant_id', $sessionParticipantId)->first()
            : null;

        if ($existing) {
            $this->hydrateFromParticipant($existing);
        } else {
            // Fresh browser tab: a stable id it will use for both the DB
            // row (once Join succeeds) and its WebRTC peer id in signaling.
            $this->participantId = (string) Str::uuid();
        }
    }

    #[On('participant-joined')]
    public function onParticipantJoined(string $participantId): void
    {
        $participant = $this->meeting->participants()->active()->where('participant_id', $participantId)->first();

        if ($participant) {
            $this->hydrateFromParticipant($participant);
        }
    }

    private function hydrateFromParticipant(Participant $participant): void
    {
        $this->participantId = $participant->participant_id;
        $this->participantRecordId = $participant->id;
        $this->hasJoined = true;
        $this->isHost = $this->isHost || $participant->is_host;
        $this->participantCount = app(ParticipantService::class)->activeCount($this->meeting);
        $this->joinedAt = $participant->joined_at?->toIso8601String();
    }

    public function leave(ParticipantService $participants): void
    {
        if ($this->hasJoined) {
            $participants->leave($this->meeting, $this->participantId);
        }

        session()->forget([
            "meeting.{$this->meeting->uuid}.participant_id",
            "meeting.{$this->meeting->uuid}.display_name",
        ]);

        // navigate: true, not a raw redirect: avoids a flash of the
        // Leave button's default label right before the page actually
        // changes (see Create::create() for the full explanation). Safe
        // here because room-alpine.js's leaveCall() already tears down
        // the caller's own camera/mic/WebRTC/Echo state via
        // controller.stop() before this method is ever called.
        $this->redirect(route('home'), navigate: true);
    }

    public function kick(string $participantId, ParticipantService $participants): void
    {
        if (! $this->isHost) {
            return;
        }

        $participants->kick($this->meeting, $participantId);
    }

    public function sendChat(string $message, ChatService $chat): void
    {
        if (! $this->hasJoined) {
            return;
        }

        // Keyed by the server-known participant id (set at join, not
        // client input), so it can't be dodged by just resending with a
        // different claimed identity the way an IP-based key could be
        // via a shared connection. Silent drop, not an error: this is
        // flood protection, not a quota anyone should ever bump into in
        // normal conversation (10 messages/10s).
        $rateLimitKey = "chat:{$this->participantId}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 10)) {
            return;
        }

        RateLimiter::hit($rateLimitKey, decaySeconds: 10);

        $chat->send(
            $this->meeting,
            $this->participantId,
            (string) session("meeting.{$this->meeting->uuid}.display_name"),
            $message,
        );
    }

    public function toggleLock(MeetingService $meetings): void
    {
        if (! $this->isHost) {
            return;
        }

        $meetings->setLocked($this->meeting, ! $this->meeting->is_locked);
        $this->meeting->refresh();
    }

    public function endMeeting(MeetingService $meetings): void
    {
        if (! $this->isHost) {
            return;
        }

        $meetings->end($this->meeting);

        // navigate: true, same reasoning as leave() above. Safe only
        // because the "End meeting" button (room.blade.php) now calls
        // controller.stop() via Alpine's endMeeting() before invoking this
        // method — without that, the host's own camera/mic/WebRTC/Echo
        // state would keep running in the background after a navigate
        // (which swaps the DOM in place rather than unloading the page,
        // unlike a hard redirect where the browser tearing down the
        // document did that cleanup for free).
        $this->redirect(route('home'), navigate: true);
    }

    public function render()
    {
        return view('livewire.meeting.room');
    }
}
