<?php

namespace Tests\Feature;

use App\Livewire\Meeting\Room;
use App\Models\Meeting;
use App\Services\ChatService;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingRoomChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_chat_persists_a_message_for_a_joined_participant(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Alice');

        $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Alice',
        ]);

        Livewire::test(Room::class, ['meeting' => $meeting])
            ->call('sendChat', 'hello everyone');

        $this->assertDatabaseHas('chat_messages', [
            'meeting_id' => $meeting->id,
            'participant_id' => $participantId,
            'display_name' => 'Alice',
            'message' => 'hello everyone',
        ]);
    }

    public function test_send_chat_is_a_no_op_before_joining(): void
    {
        $meeting = Meeting::factory()->create();

        Livewire::test(Room::class, ['meeting' => $meeting])
            ->call('sendChat', 'hello everyone');

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_room_loads_existing_chat_history_on_mount(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Alice');
        app(ChatService::class)->send($meeting, $participantId, 'Alice', 'said earlier');

        $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Alice',
        ]);

        Livewire::test(Room::class, ['meeting' => $meeting])
            ->assertSet('initialMessages.0.message', 'said earlier')
            ->assertSet('initialMessages.0.name', 'Alice');
    }

    public function test_joined_room_page_embeds_chat_history_for_alpine(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Alice');
        app(ChatService::class)->send($meeting, $participantId, 'Alice', 'said earlier');

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Alice',
        ])->get("/meet/{$meeting->uuid}");

        $response->assertSee('said earlier', false);
    }
}
