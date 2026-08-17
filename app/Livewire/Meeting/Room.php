<?php

namespace App\Livewire\Meeting;

use App\Models\Meeting;
use App\Models\Participant;
use App\Services\MeetingService;
use App\Services\ParticipantService;
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

    public function mount(Meeting $meeting): void
    {
        $this->meeting = $meeting;
        $this->isHost = $meeting->isHost(session("meeting.{$meeting->uuid}.host_token"));

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

        $this->redirect(route('home'), navigate: false);
    }

    public function kick(string $participantId, ParticipantService $participants): void
    {
        if (! $this->isHost) {
            return;
        }

        $participants->kick($this->meeting, $participantId);
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
        $this->redirect(route('home'), navigate: false);
    }

    public function render()
    {
        return view('livewire.meeting.room');
    }
}
