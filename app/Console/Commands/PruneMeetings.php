<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Two independent jobs, kept in one command since both are cheap, scheduled
 * hourly (see routes/console.php):
 *
 *  1. Flip expired-but-still-"active" meetings to "ended" so the status
 *     column reflects reality even for meetings nobody ever tries to join
 *     again (findJoinable() already blocks these reactively regardless).
 *  2. Delete meetings that ended more than 30 days ago, cascading to their
 *     participants, so an anonymous, unauthenticated app with no account
 *     deletion flow doesn't accumulate rows forever.
 */
#[Signature('app:prune-meetings')]
#[Description('End expired meetings and delete long-ended ones')]
class PruneMeetings extends Command
{
    public function handle(): int
    {
        $expired = Meeting::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'ended', 'ended_at' => now()]);

        $deleted = Meeting::query()
            ->where('status', 'ended')
            ->where('ended_at', '<=', now()->subDays(30))
            ->delete();

        $this->info("Ended {$expired} expired meeting(s); deleted {$deleted} stale meeting(s).");

        return self::SUCCESS;
    }
}
