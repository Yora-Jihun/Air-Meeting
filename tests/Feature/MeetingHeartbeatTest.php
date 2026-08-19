<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The heartbeat endpoint room.js polls every ~15s to prove a tab is still
 * genuinely open — see ParticipantService::heartbeat()/pruneStale() and
 * PruneStaleParticipants for the other half. Deliberately not tested via
 * Livewire (there's no Livewire component here); this is a plain route hit
 * with sendBeacon-style fetch(), so CSRF is bypassed the same way a real
 * browser's meta-tag token would satisfy it, just without wiring up a token
 * in the test client.
 */
class MeetingHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_bumps_last_seen_at_for_the_session_participant(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $participant = app(ParticipantService::class)->join($meeting, $participantId, 'Alice');
        $participant->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withSession(["meeting.{$meeting->uuid}.participant_id" => $participantId])
            ->post("/meet/{$meeting->uuid}/heartbeat")
            ->assertNoContent();

        $this->assertTrue($participant->fresh()->last_seen_at->gt(now()->subSeconds(5)));
    }

    public function test_heartbeat_without_a_session_participant_is_a_harmless_no_op(): void
    {
        $meeting = Meeting::factory()->create();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/meet/{$meeting->uuid}/heartbeat")
            ->assertNoContent();
    }

    public function test_heartbeat_for_an_unknown_meeting_is_not_found(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/meet/00000000-0000-4000-8000-000000000000/heartbeat')
            ->assertStatus(404);
    }
}
