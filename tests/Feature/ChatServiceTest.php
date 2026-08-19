<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Services\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_persists_a_trimmed_message(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        $message = app(ChatService::class)->send($meeting, $participantId, 'Alice', '  hello  ');

        $this->assertSame('hello', $message->message);
        $this->assertDatabaseHas('chat_messages', [
            'meeting_id' => $meeting->id,
            'participant_id' => $participantId,
            'display_name' => 'Alice',
            'message' => 'hello',
        ]);
    }

    public function test_send_strips_html_from_the_message(): void
    {
        $meeting = Meeting::factory()->create();

        $message = app(ChatService::class)->send($meeting, (string) Str::uuid(), 'Alice', '<script>alert(1)</script>hello');

        $this->assertSame('alert(1)hello', $message->message);
    }

    public function test_send_ignores_a_blank_message(): void
    {
        $meeting = Meeting::factory()->create();

        $result = app(ChatService::class)->send($meeting, (string) Str::uuid(), 'Alice', '   ');

        $this->assertNull($result);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_send_truncates_an_overlong_message_to_fit_the_column(): void
    {
        $meeting = Meeting::factory()->create();

        $message = app(ChatService::class)->send($meeting, (string) Str::uuid(), 'Alice', str_repeat('a', 600));

        $this->assertSame(500, strlen($message->message));
    }

    public function test_send_succeeds_even_when_the_broadcaster_is_unreachable(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.options.host', '127.0.0.1');
        config()->set('broadcasting.connections.reverb.options.port', 1);

        $meeting = Meeting::factory()->create();

        $message = app(ChatService::class)->send($meeting, (string) Str::uuid(), 'Alice', 'hello');

        $this->assertNotNull($message);
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_recent_for_returns_messages_oldest_first(): void
    {
        $meeting = Meeting::factory()->create();
        $service = app(ChatService::class);

        $service->send($meeting, (string) Str::uuid(), 'Alice', 'first');
        $service->send($meeting, (string) Str::uuid(), 'Bob', 'second');

        $messages = $service->recentFor($meeting);

        $this->assertSame(['first', 'second'], $messages->pluck('message')->all());
    }

    public function test_recent_for_is_scoped_to_the_meeting(): void
    {
        $meetingA = Meeting::factory()->create();
        $meetingB = Meeting::factory()->create();
        $service = app(ChatService::class);

        $service->send($meetingA, (string) Str::uuid(), 'Alice', 'for A');
        $service->send($meetingB, (string) Str::uuid(), 'Bob', 'for B');

        $messages = $service->recentFor($meetingA);

        $this->assertCount(1, $messages);
        $this->assertSame('for A', $messages->first()->message);
    }
}
