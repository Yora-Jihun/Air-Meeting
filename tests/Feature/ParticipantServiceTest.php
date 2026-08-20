<?php

namespace Tests\Feature;

use App\Events\HostPromoted;
use App\Events\MeetingEnded;
use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_creates_a_participant(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        $participant = app(ParticipantService::class)->join($meeting, $participantId, 'Alice');

        $this->assertDatabaseHas('participants', [
            'meeting_id' => $meeting->id,
            'participant_id' => $participantId,
            'display_name' => 'Alice',
        ]);
        $this->assertNull($participant->left_at);
    }

    public function test_rejoining_with_the_same_participant_id_reactivates_the_row(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $first = $service->join($meeting, $participantId, 'Alice');
        $service->leave($meeting, $participantId);
        $second = $service->join($meeting, $participantId, 'Alice');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $meeting->participants()->count());
        $this->assertSame(1, app(ParticipantService::class)->activeCount($meeting));
    }

    public function test_leave_marks_the_participant_inactive(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $service->join($meeting, $participantId, 'Alice');
        $service->leave($meeting, $participantId);

        $this->assertSame(0, $service->activeCount($meeting));
    }

    public function test_kick_succeeds_even_when_the_broadcaster_is_unreachable(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
        config()->set('broadcasting.connections.reverb.options.port', 1);

        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $service->join($meeting, $participantId, 'Alice');
        $service->kick($meeting, $participantId);

        $this->assertSame(0, $service->activeCount($meeting));
    }

    public function test_active_count_ignores_participants_who_left(): void
    {
        $meeting = Meeting::factory()->create();
        $meeting->participants()->create([
            'participant_id' => (string) Str::uuid(),
            'display_name' => 'Gone',
            'joined_at' => now(),
            'left_at' => now(),
        ]);
        $meeting->participants()->create([
            'participant_id' => (string) Str::uuid(),
            'display_name' => 'Here',
            'joined_at' => now(),
        ]);

        $this->assertSame(1, app(ParticipantService::class)->activeCount($meeting));
    }

    public function test_join_stamps_an_initial_last_seen_at(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        $participant = app(ParticipantService::class)->join($meeting, $participantId, 'Alice');

        $this->assertNotNull($participant->last_seen_at);
    }

    public function test_heartbeat_bumps_last_seen_at_for_an_active_participant(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $participant = $service->join($meeting, $participantId, 'Alice');
        $participant->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $service->heartbeat($meeting, $participantId);

        $this->assertTrue($participant->fresh()->last_seen_at->gt(now()->subSeconds(5)));
    }

    public function test_heartbeat_does_nothing_for_a_participant_who_already_left(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $participant = $service->join($meeting, $participantId, 'Alice');
        $service->leave($meeting, $participantId);
        $staleTime = now()->subMinutes(10);
        $participant->forceFill(['last_seen_at' => $staleTime])->save();

        $service->heartbeat($meeting, $participantId);

        $this->assertSame(
            $staleTime->toDateTimeString(),
            $participant->fresh()->last_seen_at->toDateTimeString(),
        );
    }

    public function test_prune_stale_marks_participants_left_once_their_heartbeat_goes_quiet(): void
    {
        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);

        $stale = $service->join($meeting, (string) Str::uuid(), 'Ghost');
        $stale->forceFill(['last_seen_at' => now()->subSeconds(90)])->save();

        $fresh = $service->join($meeting, (string) Str::uuid(), 'Present');
        $fresh->forceFill(['last_seen_at' => now()])->save();

        $pruned = $service->pruneStale(staleAfterSeconds: 45);

        $this->assertSame(1, $pruned);
        $this->assertNotNull($stale->fresh()->left_at);
        $this->assertNull($fresh->fresh()->left_at);
    }

    public function test_prune_stale_never_touches_a_row_with_no_heartbeat_yet(): void
    {
        $meeting = Meeting::factory()->create();
        $meeting->participants()->create([
            'participant_id' => (string) Str::uuid(),
            'display_name' => 'Pre-migration row',
            'joined_at' => now()->subDays(1),
            'last_seen_at' => null,
        ]);

        $pruned = app(ParticipantService::class)->pruneStale(staleAfterSeconds: 45);

        $this->assertSame(0, $pruned);
    }

    public function test_the_host_leaving_promotes_whoever_has_been_present_longest(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();

        $host = $service->join($meeting, $hostId, 'Host', isHost: true);
        $early = $service->join($meeting, (string) Str::uuid(), 'Early guest');
        $early->forceFill(['joined_at' => now()->subMinutes(5)])->save();
        $service->join($meeting, (string) Str::uuid(), 'Later guest');

        $service->leave($meeting, $hostId);

        $this->assertTrue($early->fresh()->is_host);
        Event::assertDispatched(HostPromoted::class, fn ($event) => $event->participantId === $early->participant_id);
    }

    public function test_the_host_leaving_does_not_promote_anyone_if_a_co_host_is_already_present(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();

        $service->join($meeting, $hostId, 'Host', isHost: true);

        // Bypassing join() deliberately: with demoteOtherHosts() in place,
        // joining a second participant with isHost: true would demote the
        // first immediately — there is never a way to legitimately reach
        // two simultaneous hosts through the service anymore. This directly
        // constructs that state anyway (as if it existed for some other
        // reason) purely to test promoteNextHost()'s own defensive check —
        // it must not touch an already-hosted meeting, regardless of how
        // that host status was reached.
        $coHost = $service->join($meeting, (string) Str::uuid(), 'Co-host');
        $coHost->forceFill(['is_host' => true])->save();

        $service->leave($meeting, $hostId);

        $this->assertTrue($coHost->fresh()->is_host);
        Event::assertNotDispatched(HostPromoted::class);
    }

    public function test_a_non_host_leaving_never_triggers_a_promotion(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);

        $service->join($meeting, (string) Str::uuid(), 'Host', isHost: true);
        $guestId = (string) Str::uuid();
        $service->join($meeting, $guestId, 'Guest');

        $service->leave($meeting, $guestId);

        Event::assertNotDispatched(HostPromoted::class);
    }

    public function test_the_only_participant_leaving_has_no_successor_to_promote(): void
    {
        Event::fake([HostPromoted::class, MeetingEnded::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();

        $service->join($meeting, $hostId, 'Host', isHost: true);
        $service->leave($meeting, $hostId);

        Event::assertNotDispatched(HostPromoted::class);
        $this->assertSame(0, $service->activeCount($meeting));

        // With no successor to hand host status to, the now-empty meeting
        // is ended immediately rather than left dangling `active`.
        $meeting->refresh();
        $this->assertSame('ended', $meeting->status);
        $this->assertNotNull($meeting->ended_at);
        $this->assertDatabaseCount('participants', 0);
        Event::assertDispatched(MeetingEnded::class, fn ($event) => $event->meetingUuid === $meeting->uuid);
    }

    public function test_the_host_leaving_does_not_end_the_meeting_when_a_co_host_remains(): void
    {
        Event::fake([HostPromoted::class, MeetingEnded::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();

        $service->join($meeting, $hostId, 'Host', isHost: true);
        $coHost = $service->join($meeting, (string) Str::uuid(), 'Co-host');
        $coHost->forceFill(['is_host' => true])->save();

        $service->leave($meeting, $hostId);

        Event::assertNotDispatched(MeetingEnded::class);
        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_prune_stale_promotes_a_successor_when_the_stale_participant_was_host(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);

        $host = $service->join($meeting, (string) Str::uuid(), 'Host', isHost: true);
        $host->forceFill(['last_seen_at' => now()->subSeconds(90)])->save();

        $successor = $service->join($meeting, (string) Str::uuid(), 'Guest');
        $successor->forceFill(['last_seen_at' => now()])->save();

        $service->pruneStale(staleAfterSeconds: 45);

        $this->assertNotNull($host->fresh()->left_at);
        $this->assertTrue($successor->fresh()->is_host);
        Event::assertDispatched(HostPromoted::class, fn ($event) => $event->participantId === $successor->participant_id);
    }

    /**
     * Regression test for a real bug found in production data: the host
     * left, a successor was correctly promoted, and the real host later
     * rejoined — under a *different* display name, so a brand new
     * participant row, but the same browser session still carrying the
     * host_token. Nothing demoted the successor, leaving two participants
     * flagged is_host at once.
     */
    public function test_the_real_host_rejoining_under_a_new_identity_demotes_the_temporary_successor(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();

        $service->join($meeting, $hostId, 'Jirrum', isHost: true);
        $successor = $service->join($meeting, (string) Str::uuid(), 'two');
        $service->join($meeting, (string) Str::uuid(), 'three');

        $service->leave($meeting, $hostId);
        $this->assertTrue($successor->fresh()->is_host);

        // Same session (still holding the real host_token), rejoining as
        // a brand new participant row under a new display name.
        $rejoined = $service->join($meeting, (string) Str::uuid(), 'yora', isHost: true);

        $this->assertTrue($rejoined->fresh()->is_host);
        $this->assertFalse($successor->fresh()->is_host);
        $this->assertSame(1, $meeting->activeParticipants()->where('is_host', true)->count());
        Event::assertDispatched(HostPromoted::class, fn ($event) => $event->participantId === $rejoined->participant_id);
    }

    public function test_a_normal_join_with_no_conflicting_host_does_not_broadcast(): void
    {
        Event::fake([HostPromoted::class]);

        $meeting = Meeting::factory()->create();
        $service = app(ParticipantService::class);

        $service->join($meeting, (string) Str::uuid(), 'Host', isHost: true);

        Event::assertNotDispatched(HostPromoted::class);
    }
}
