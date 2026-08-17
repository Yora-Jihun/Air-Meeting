<?php

use App\Models\Participant;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Presence channel: meeting.{uuid}
|--------------------------------------------------------------------------
|
| There is no real login in this app, but Laravel's PusherBroadcaster::auth()
| unconditionally requires $request->user() to be truthy for ANY
| private/presence channel before it will even call this callback — that
| check happens regardless of what this callback would otherwise allow.
| The `guards: 'participant'` option below points it at a lightweight
| request-based guard (see App\Providers\AppServiceProvider) that resolves
| the session-based identity App\Livewire\Meeting\Join already establishes,
| purely to satisfy that framework requirement — it is not a login system.
|
| By the time this callback runs, $participant is already the correctly
| scoped, still-active Participant row for this exact meeting (or null),
| so this only has to shape the presence "member" data delivered to every
| other client (via Echo's `here`/`joining`/`leaving`) — the participant
| list and peer ids used for WebRTC signaling are driven entirely by this,
| not client input.
*/
Broadcast::channel('meeting.{uuid}', function (?Participant $participant, string $uuid) {
    if (! $participant) {
        return false;
    }

    return [
        'id' => $participant->participant_id,
        'name' => $participant->display_name,
        'is_host' => $participant->is_host,
        'joined_at' => $participant->joined_at?->toIso8601String(),
    ];
}, ['guards' => 'participant']);
