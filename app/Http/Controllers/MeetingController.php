<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use Illuminate\View\View;

class MeetingController extends Controller
{
    public function index(): View
    {
        return view('home');
    }

    /**
     * Renders the meeting page shell. This only checks that the UUID
     * resolves to a meeting that isn't already over — password, lock,
     * and capacity checks are enforced server-side again when the
     * participant actually attempts to join (App\Livewire\Meeting\Join),
     * since a page load happening earlier than a fellow participant's
     * "lock the room" click can never be trusted as the final word.
     */
    public function show(Meeting $meeting): View
    {
        if ($meeting->status === 'ended') {
            return view('meeting.unavailable', ['reason' => 'This meeting has ended.']);
        }

        if ($meeting->isExpired()) {
            return view('meeting.unavailable', ['reason' => 'This meeting link has expired.']);
        }

        return view('meeting.show', ['meeting' => $meeting]);
    }
}
