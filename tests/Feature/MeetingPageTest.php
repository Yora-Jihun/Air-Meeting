<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_loads(): void
    {
        $this->get('/')->assertStatus(200)->assertSeeLivewire('meeting.create');
    }

    public function test_unknown_meeting_uuid_is_not_found(): void
    {
        $this->get('/meet/00000000-0000-4000-8000-000000000000')->assertStatus(404);
    }

    public function test_malformed_uuid_is_rejected_before_hitting_the_database(): void
    {
        $this->get('/meet/not-a-uuid')->assertStatus(404);
    }

    public function test_ended_meeting_shows_unavailable_page_instead_of_the_room(): void
    {
        $meeting = Meeting::factory()->ended()->create();

        $this->get("/meet/{$meeting->uuid}")
            ->assertStatus(200)
            ->assertSee('This meeting has ended.');
    }

    public function test_active_meeting_renders_the_room_shell(): void
    {
        $meeting = Meeting::factory()->create();

        $this->get("/meet/{$meeting->uuid}")
            ->assertStatus(200)
            ->assertSeeLivewire('meeting.room');
    }

    /**
     * Regression test: this route used to be hit via navigator.sendBeacon()
     * on the browser's `pagehide` event to mark a participant as having
     * left. The bug: `pagehide` also fires on a plain page refresh, not
     * just closing the tab — so every refresh silently marked the
     * participant as gone, and the reloaded page then showed the Join
     * screen again instead of reconnecting them to the room they never
     * actually left. Removed entirely rather than patched, since there is
     * no reliable client-side signal to tell a refresh apart from a real
     * close, and a "grace period" alternative would let a host-kicked
     * participant silently rejoin by refreshing within the window.
     */
    public function test_the_removed_beacon_leave_route_no_longer_exists(): void
    {
        $meeting = Meeting::factory()->create();

        $this->post("/meet/{$meeting->uuid}/leave")->assertStatus(404);
    }
}
