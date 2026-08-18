<?php

namespace Tests\Feature;

use App\Exceptions\MeetingUnavailableException;
use App\Models\Meeting;
use App\Services\ChatService;
use App\Services\MeetingService;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_generates_a_uuid_and_host_token(): void
    {
        $meeting = app(MeetingService::class)->create(title: 'Standup');

        $this->assertNotEmpty($meeting->uuid);
        $this->assertNotEmpty($meeting->host_token);
        $this->assertNotEquals($meeting->uuid, $meeting->host_token);
        $this->assertSame('active', $meeting->status);
    }

    public function test_find_joinable_rejects_unknown_uuid(): void
    {
        $this->expectException(MeetingUnavailableException::class);

        app(MeetingService::class)->findJoinable((string) Str::uuid());
    }

    public function test_find_joinable_rejects_ended_meeting(): void
    {
        $meeting = Meeting::factory()->ended()->create();

        $this->expectExceptionMessage('This meeting has ended.');

        app(MeetingService::class)->findJoinable($meeting->uuid);
    }

    public function test_find_joinable_rejects_expired_meeting(): void
    {
        $meeting = Meeting::factory()->expired()->create();

        $this->expectExceptionMessage('This meeting link has expired.');

        app(MeetingService::class)->findJoinable($meeting->uuid);
    }

    public function test_find_joinable_rejects_locked_meeting(): void
    {
        $meeting = Meeting::factory()->locked()->create();

        $this->expectExceptionMessage('The host has locked this meeting.');

        app(MeetingService::class)->findJoinable($meeting->uuid);
    }

    public function test_find_joinable_requires_correct_password(): void
    {
        $meeting = Meeting::factory()->withPassword('letmein')->create();

        $this->expectExceptionMessage('Incorrect meeting password.');

        app(MeetingService::class)->findJoinable($meeting->uuid, 'wrong');
    }

    public function test_find_joinable_accepts_correct_password(): void
    {
        $meeting = Meeting::factory()->withPassword('letmein')->create();

        $resolved = app(MeetingService::class)->findJoinable($meeting->uuid, 'letmein');

        $this->assertTrue($resolved->is($meeting));
    }

    public function test_find_joinable_rejects_full_meeting(): void
    {
        $meeting = Meeting::factory()->create(['max_participants' => 1]);
        $meeting->participants()->create([
            'participant_id' => (string) Str::uuid(),
            'display_name' => 'Already Here',
            'joined_at' => now(),
        ]);

        $this->expectExceptionMessage('This meeting is full.');

        app(MeetingService::class)->findJoinable($meeting->uuid);
    }

    public function test_end_marks_meeting_ended(): void
    {
        $meeting = Meeting::factory()->create();

        app(MeetingService::class)->end($meeting);

        $this->assertSame('ended', $meeting->fresh()->status);
        $this->assertNotNull($meeting->fresh()->ended_at);
    }

    public function test_end_succeeds_even_when_the_broadcaster_is_unreachable(): void
    {
        // Reproduces a real bug: MeetingEnded is ShouldBroadcastNow, so with
        // BROADCAST_CONNECTION=reverb and no Reverb process running, the
        // PusherBroadcaster's cURL call to it used to throw and turn an
        // already-successful DB update into a 500 for the host clicking
        // "End meeting". Broadcasting must be best-effort, not load-bearing.
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
        config()->set('broadcasting.connections.reverb.options.port', 1);

        $meeting = Meeting::factory()->create();

        app(MeetingService::class)->end($meeting);

        $this->assertSame('ended', $meeting->fresh()->status);
    }

    public function test_end_deletes_chat_and_participants_but_keeps_the_meeting_row(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Alice');
        app(ChatService::class)->send($meeting, $participantId, 'Alice', 'hello');

        app(MeetingService::class)->end($meeting);

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => 'ended']);
    }

    public function test_is_host_only_matches_the_correct_token(): void
    {
        $meeting = Meeting::factory()->create();

        $this->assertTrue($meeting->isHost($meeting->host_token));
        $this->assertFalse($meeting->isHost('not-the-token'));
        $this->assertFalse($meeting->isHost(null));
    }
}
