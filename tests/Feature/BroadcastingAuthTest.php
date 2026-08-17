<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression tests for a real bug: Laravel's PusherBroadcaster::auth()
 * unconditionally requires $request->user() to be truthy for ANY
 * private/presence channel — before it ever consults Broadcast::channel()'s
 * own callback in routes/channels.php. Since this app has no login,
 * $request->user() was always null, so every presence-channel subscription
 * (i.e. every attempt to join a meeting's WebRTC signaling) 403'd, no
 * matter how valid the participant's session was.
 *
 * Fixed by a lightweight "participant" guard (App\Providers\AppServiceProvider)
 * registered via Auth::viaRequest() and wired into the channel via the
 * `guards: 'participant'` option — not real login, just enough to satisfy
 * that framework requirement using the session identity Join already sets.
 *
 * These tests only mean anything with BROADCAST_CONNECTION=reverb active
 * from boot (see phpunit.xml) — Broadcast::channel() registers its pattern
 * and options on whichever broadcaster driver is resolved when
 * routes/channels.php loads, and that resolution is cached, so overriding
 * config() mid-test would register against a broadcaster instance that
 * /broadcasting/auth never actually uses.
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_joined_participant_can_authorize_their_meetings_presence_channel(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Alice');

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Alice',
        ])->post('/broadcasting/auth', [
            'channel_name' => "presence-meeting.{$meeting->uuid}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);

        $channelData = json_decode($response->json('channel_data'), true);
        $this->assertSame('Alice', $channelData['user_info']['name']);
        $this->assertSame($participantId, $channelData['user_info']['id']);

        // The participant list shows "joined X ago" for everyone, not just
        // yourself — other clients need this participant's real join time,
        // not "whenever I happened to discover them" via presence.
        $this->assertNotNull($channelData['user_info']['joined_at']);
    }

    public function test_a_visitor_who_never_joined_cannot_authorize_the_channel(): void
    {
        $meeting = Meeting::factory()->create();

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "presence-meeting.{$meeting->uuid}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_session_for_one_meeting_cannot_authorize_a_different_meetings_channel(): void
    {
        $joinedMeeting = Meeting::factory()->create();
        $otherMeeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($joinedMeeting, $participantId, 'Alice');

        $response = $this->withSession([
            "meeting.{$joinedMeeting->uuid}.participant_id" => $participantId,
        ])->post('/broadcasting/auth', [
            'channel_name' => "presence-meeting.{$otherMeeting->uuid}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_a_participant_who_left_cannot_authorize_the_channel(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();
        $service = app(ParticipantService::class);

        $service->join($meeting, $participantId, 'Alice');
        $service->leave($meeting, $participantId);

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
        ])->post('/broadcasting/auth', [
            'channel_name' => "presence-meeting.{$meeting->uuid}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }
}
