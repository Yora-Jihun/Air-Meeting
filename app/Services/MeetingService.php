<?php

namespace App\Services;

use App\Concerns\BroadcastsQuietly;
use App\Events\MeetingEnded;
use App\Exceptions\MeetingUnavailableException;
use App\Models\Meeting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;

/**
 * Owns meeting lifecycle rules (creation, expiry, locking, ending).
 * Kept out of Livewire components so those rules stay testable in
 * isolation and reusable from console commands / an API later.
 */
class MeetingService
{
    use BroadcastsQuietly;

    public function create(?string $title = null, ?string $password = null, ?CarbonInterface $expiresAt = null): Meeting
    {
        return Meeting::create([
            'title' => $title,
            'password' => $password ? Hash::make($password) : null,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Resolve a meeting UUID into a joinable Meeting, or throw a specific
     * reason why it can't be joined. Never trust "is this meeting valid"
     * decisions to the client — this is the single choke point for them.
     */
    public function findJoinable(string $uuid, ?string $password = null): Meeting
    {
        $meeting = Meeting::where('uuid', $uuid)->first();

        if (! $meeting) {
            throw MeetingUnavailableException::notFound();
        }

        if ($meeting->status === 'ended') {
            throw MeetingUnavailableException::ended();
        }

        if ($meeting->isExpired()) {
            throw MeetingUnavailableException::expired();
        }

        if ($meeting->is_locked) {
            throw MeetingUnavailableException::locked();
        }

        if ($meeting->password && ! Hash::check((string) $password, $meeting->password)) {
            throw MeetingUnavailableException::invalidPassword();
        }

        if ($meeting->isFull()) {
            throw MeetingUnavailableException::full();
        }

        return $meeting;
    }

    public function requiresPassword(Meeting $meeting): bool
    {
        return $meeting->password !== null;
    }

    public function setLocked(Meeting $meeting, bool $locked): void
    {
        $meeting->update(['is_locked' => $locked]);
    }

    public function end(Meeting $meeting): void
    {
        $meeting->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        $this->broadcastQuietly(new MeetingEnded($meeting->uuid));
    }
}
