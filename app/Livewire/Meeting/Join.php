<?php

namespace App\Livewire\Meeting;

use App\Exceptions\MeetingUnavailableException;
use App\Models\Meeting;
use App\Services\MeetingService;
use App\Services\ParticipantService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Join extends Component
{
    public Meeting $meeting;

    public string $participantId;

    #[Validate('required|string|min:1|max:50')]
    public string $displayName = '';

    public ?string $password = null;

    public ?string $error = null;

    public function mount(Meeting $meeting, string $participantId): void
    {
        $this->meeting = $meeting;
        $this->participantId = $participantId;
    }

    public function getRequiresPasswordProperty(): bool
    {
        return app(MeetingService::class)->requiresPassword($this->meeting);
    }

    public function join(MeetingService $meetings, ParticipantService $participants): void
    {
        $this->error = null;

        // strip_tags defends the value at rest (it's later broadcast to
        // other participants' browsers over the presence channel); Blade's
        // {{ }} escaping independently defends every place it's echoed.
        $this->displayName = trim(strip_tags($this->validate()['displayName']));

        if ($this->displayName === '') {
            $this->addError('displayName', 'Please enter a display name.');

            return;
        }

        // Only wrong-password guesses count against this — never a locked,
        // full, or expired meeting — so a burst of real guests joining an
        // *open* meeting from a shared office IP is never at risk of
        // tripping it. Scoped per meeting, not globally per IP, so one
        // locked-down meeting can't exhaust an attacker's budget against
        // every other meeting on the server.
        $rateLimitKey = "meeting-password:{$this->meeting->uuid}:".request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, maxAttempts: 10)) {
            $this->error = 'Too many attempts. Please wait a minute and try again.';

            return;
        }

        try {
            $meeting = $meetings->findJoinable($this->meeting->uuid, $this->password);
        } catch (MeetingUnavailableException $e) {
            if ($e->reason === 'invalid_password') {
                RateLimiter::hit($rateLimitKey, decaySeconds: 60);
            }

            $this->error = $e->getMessage();

            return;
        }

        RateLimiter::clear($rateLimitKey);

        $isHost = $meeting->isHost(session("meeting.{$meeting->uuid}.host_token"));

        $participant = $participants->join($meeting, $this->participantId, $this->displayName, $isHost);

        session([
            "meeting.{$meeting->uuid}.participant_id" => $participant->participant_id,
            "meeting.{$meeting->uuid}.display_name" => $participant->display_name,
        ]);

        $this->dispatch('participant-joined', participantId: $participant->participant_id);
    }

    public function render()
    {
        return view('livewire.meeting.join');
    }
}
