<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Http\Response;
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

    /**
     * Polled every ~15s by room.js while a participant's tab is open. This
     * only ever refreshes last_seen_at for the caller's own session
     * participant — it never marks anyone as left — so unlike the removed
     * pagehide/sendBeacon leave attempt (see MeetingPageTest's regression
     * test), there's no way for a page refresh to be misread as a
     * departure. Absence of heartbeats, not their presence, is what
     * app:prune-stale-participants acts on.
     */
    public function heartbeat(Meeting $meeting, ParticipantService $participants): Response
    {
        $participantId = session("meeting.{$meeting->uuid}.participant_id");

        if ($participantId) {
            $participants->heartbeat($meeting, $participantId);
        }

        return response()->noContent();
    }
}
