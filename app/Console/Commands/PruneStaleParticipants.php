<?php

namespace App\Console\Commands;

use App\Services\ParticipantService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The server-side half of the heartbeat mechanism (see ParticipantService::
 * heartbeat()/pruneStale() and room.js): scheduled frequently, unlike
 * PruneMeetings' hourly sweep, since a participant whose tab closed without
 * calling leave() should stop occupying a capacity slot within seconds, not
 * hours.
 */
#[Signature('app:prune-stale-participants')]
#[Description('Mark participants inactive once their heartbeat has gone stale')]
class PruneStaleParticipants extends Command
{
    public function handle(ParticipantService $participants): int
    {
        $pruned = $participants->pruneStale();

        $this->info("Marked {$pruned} stale participant(s) as left.");

        return self::SUCCESS;
    }
}
