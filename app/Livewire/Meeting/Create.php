<?php

namespace App\Livewire\Meeting;

use App\Services\MeetingService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Create extends Component
{
    #[Validate('nullable|string|max:100')]
    public ?string $title = null;

    #[Validate('nullable|string|min:4|max:50')]
    public ?string $password = null;

    #[Validate('nullable|in:1,24,168')]
    public ?string $expiresInHours = null;

    public bool $showOptions = false;

    public function create(MeetingService $meetings)
    {
        $this->validate();

        $meeting = $meetings->create(
            title: $this->title ?: null,
            password: $this->password ?: null,
            expiresAt: $this->expiresInHours ? Carbon::now()->addHours((int) $this->expiresInHours) : null,
        );

        // The creator is recognized as host purely by holding this token —
        // there is no login, so possession of the (server-issued) token in
        // the session is the only credential. It never leaves the server
        // except embedded in this redirect's session write.
        session(["meeting.{$meeting->uuid}.host_token" => $meeting->host_token]);

        return $this->redirect(route('meeting.show', $meeting), navigate: false);
    }

    public function render()
    {
        return view('livewire.meeting.create');
    }
}
