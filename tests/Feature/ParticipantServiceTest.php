<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
