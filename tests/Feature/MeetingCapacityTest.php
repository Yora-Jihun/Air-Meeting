<?php

namespace Tests\Feature;

use App\Exceptions\MeetingUnavailableException;
use App\Livewire\Meeting\Join;
use App\Livewire\Meeting\Room;
use App\Models\Meeting;
use App\Services\MeetingService;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the "many users join the same meeting" path end to end: up to
 * the configured cap, everyone gets in and the room renders correctly with
 * a full participant list; past the cap, joining is rejected the same way
 * a locked or expired meeting is. This only covers what's actually testable
 * server-side (capacity accounting, DB/render correctness at scale) — the
 * WebRTC mesh itself (every participant's browser opens a direct
 * RTCPeerConnection to every other participant, see room.js) is a real,
 * separate scaling ceiling that no amount of backend testing can measure;
 * that's inherent to the mesh topology, not something this app's PHP layer
 * enforces or can fix.
 */
class MeetingCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_participants_can_join_up_to_the_configured_cap(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);

        for ($i = 1; $i <= 12; $i++) {
            $service->join($meeting, (string) Str::uuid(), "Guest {$i}");
        }

        $this->assertSame(12, $service->activeCount($meeting));
        $this->assertTrue($meeting->fresh()->isFull());
    }

    public function test_joining_past_the_cap_is_rejected_with_the_same_error_as_a_full_meeting(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);

        for ($i = 1; $i <= 12; $i++) {
            $service->join($meeting, (string) Str::uuid(), "Guest {$i}");
        }

        $this->expectException(MeetingUnavailableException::class);
        $this->expectExceptionMessage('This meeting is full.');

        app(MeetingService::class)->findJoinable($meeting->uuid);
    }

    public function test_a_participant_leaving_a_full_meeting_frees_a_slot_for_the_next_joiner(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);
        $participantIds = [];

        for ($i = 1; $i <= 12; $i++) {
            $participantIds[] = $id = (string) Str::uuid();
            $service->join($meeting, $id, "Guest {$i}");
        }

        $service->leave($meeting, $participantIds[0]);

        $resolved = app(MeetingService::class)->findJoinable($meeting->uuid);
        $this->assertTrue($resolved->is($meeting));
    }

    public function test_the_thirteenth_join_attempt_through_the_real_livewire_flow_is_rejected(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);

        for ($i = 1; $i <= 12; $i++) {
            $service->join($meeting, (string) Str::uuid(), "Guest {$i}");
        }

        Livewire::test(Join::class, [
            'meeting' => $meeting,
            'participantId' => (string) Str::uuid(),
        ])
            ->set('displayName', 'Latecomer')
            ->call('join')
            ->assertSet('error', 'This meeting is full.')
            ->assertNotDispatched('participant-joined');

        $this->assertSame(12, $service->activeCount($meeting));
    }

    public function test_room_renders_correctly_with_a_full_participant_list(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();
        $service->join($meeting, $hostId, 'Host', isHost: true);

        for ($i = 1; $i <= 11; $i++) {
            $service->join($meeting, (string) Str::uuid(), "Guest {$i}");
        }

        $html = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $hostId,
            "meeting.{$meeting->uuid}.display_name" => 'Host',
        ])->get("/meet/{$meeting->uuid}")->getContent();

        $this->assertStringContainsString('x-init="init()"', $html);
        $this->assertStringNotContainsString('@js(', $html);
    }

    public function test_room_component_reports_the_correct_participant_count_when_full(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 12]);
        $service = app(ParticipantService::class);
        $hostId = (string) Str::uuid();
        $service->join($meeting, $hostId, 'Host', isHost: true);

        for ($i = 1; $i <= 11; $i++) {
            $service->join($meeting, (string) Str::uuid(), "Guest {$i}");
        }

        $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $hostId,
            "meeting.{$meeting->uuid}.display_name" => 'Host',
        ]);

        Livewire::test(Room::class, ['meeting' => $meeting])
            ->assertSet('participantCount', 12);
    }
}
