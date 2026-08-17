<?php

namespace Tests\Feature;

use App\Livewire\Meeting\Join;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_joining_with_a_display_name_creates_a_participant_and_dispatches_event(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        Livewire::test(Join::class, ['meeting' => $meeting, 'participantId' => $participantId])
            ->set('displayName', 'Alice')
            ->call('join')
            ->assertDispatched('participant-joined', participantId: $participantId);

        $this->assertDatabaseHas('participants', [
            'meeting_id' => $meeting->id,
            'participant_id' => $participantId,
            'display_name' => 'Alice',
        ]);
    }

    public function test_display_name_is_required(): void
    {
        $meeting = Meeting::factory()->create();

        Livewire::test(Join::class, ['meeting' => $meeting, 'participantId' => (string) Str::uuid()])
            ->set('displayName', '')
            ->call('join')
            ->assertHasErrors('displayName');
    }

    public function test_html_is_stripped_from_display_names(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        Livewire::test(Join::class, ['meeting' => $meeting, 'participantId' => $participantId])
            ->set('displayName', '<script>alert(1)</script>Bob')
            ->call('join');

        $this->assertDatabaseHas('participants', [
            'participant_id' => $participantId,
            'display_name' => 'alert(1)Bob',
        ]);
    }

    public function test_joining_a_locked_meeting_surfaces_a_server_side_error(): void
    {
        $meeting = Meeting::factory()->locked()->create();

        Livewire::test(Join::class, ['meeting' => $meeting, 'participantId' => (string) Str::uuid()])
            ->set('displayName', 'Alice')
            ->call('join')
            ->assertSet('error', 'The host has locked this meeting.')
            ->assertNotDispatched('participant-joined');
    }

    public function test_joining_a_full_meeting_is_rejected_even_if_the_page_loaded_before_it_filled_up(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 1]);
        $meeting->participants()->create([
            'participant_id' => (string) Str::uuid(),
            'display_name' => 'Already Here',
            'joined_at' => now(),
        ]);

        Livewire::test(Join::class, ['meeting' => $meeting, 'participantId' => (string) Str::uuid()])
            ->set('displayName', 'Latecomer')
            ->call('join')
            ->assertSet('error', 'This meeting is full.');
    }
}
