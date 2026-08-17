<?php

namespace App\Concerns;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasting (Reverb) is a real-time nicety layered on top of database
 * state that is already the source of truth by the time these events fire
 * (the meeting is already "ended", the participant already "left" in the
 * DB). If the WebSocket server happens to be down, participants simply
 * don't get the instant push — they still see correct state on their next
 * page load / signaling reconnect — so a broadcast failure must never
 * fail the request that already completed the real work.
 */
trait BroadcastsQuietly
{
    protected function broadcastQuietly(ShouldBroadcast $event, bool $toOthers = false): void
    {
        try {
            // PendingBroadcast only actually dispatches (and can throw) from
            // its __destruct(), so it's explicitly unset inside the try
            // block rather than left as an unassigned temporary — that
            // guarantees the dispatch happens (and is caught) right here.
            $pending = broadcast($event);

            if ($toOthers) {
                $pending->toOthers();
            }

            unset($pending);
        } catch (Throwable $e) {
            Log::warning('Broadcast failed, continuing without it', [
                'event' => $event::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
