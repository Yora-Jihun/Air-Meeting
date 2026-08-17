<?php

namespace Tests\Feature;

use App\Livewire\Meeting\Create;
use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_meeting_redirects_to_its_room(): void
    {
        Livewire::test(Create::class)
            ->set('title', 'Weekly Sync')
            ->call('create')
            ->assertRedirect();

        $this->assertDatabaseHas('meetings', ['title' => 'Weekly Sync']);
    }

    public function test_creator_is_recognized_as_host_after_redirect(): void
    {
        Livewire::test(Create::class)->call('create');

        $meeting = Meeting::query()->latest('id')->first();

        $this->assertTrue($meeting->isHost(session("meeting.{$meeting->uuid}.host_token")));
    }
}
