<?php

namespace Tests\Feature;

use App\Livewire\Meeting\Create;
use App\Models\Meeting;
use App\Services\MeetingService;
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

    public function test_leaving_expiry_unset_creates_a_meeting_that_never_expires(): void
    {
        // The "Never" <option> in the expiry <select> submits an empty
        // string, not null — this is what actually exercises that path.
        Livewire::test(Create::class)
            ->set('expiresInHours', '')
            ->call('create');

        $meeting = Meeting::query()->latest('id')->first();

        $this->assertNull($meeting->expires_at);
        $this->assertFalse($meeting->isExpired());
    }

    public function test_choosing_an_expiry_option_sets_the_expiration_time(): void
    {
        Livewire::test(Create::class)
            ->set('expiresInHours', '24')
            ->call('create');

        $meeting = Meeting::query()->latest('id')->first();

        $this->assertNotNull($meeting->expires_at);
        $this->assertTrue($meeting->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_an_invalid_expiry_option_is_rejected(): void
    {
        Livewire::test(Create::class)
            ->set('expiresInHours', '999')
            ->call('create')
            ->assertHasErrors('expiresInHours');

        $this->assertDatabaseCount('meetings', 0);
    }

    public function test_setting_a_password_hashes_it_and_requires_it_to_join(): void
    {
        Livewire::test(Create::class)
            ->set('password', 'letmein')
            ->call('create');

        $meeting = Meeting::query()->latest('id')->first();

        $this->assertNotNull($meeting->password);
        $this->assertNotSame('letmein', $meeting->password);
        $this->assertTrue(app(MeetingService::class)->requiresPassword($meeting));
    }

    public function test_leaving_password_blank_creates_a_meeting_that_needs_no_password(): void
    {
        Livewire::test(Create::class)->call('create');

        $meeting = Meeting::query()->latest('id')->first();

        $this->assertFalse(app(MeetingService::class)->requiresPassword($meeting));
    }

    /**
     * Regression test for a real bug: the x-data attribute in
     * create.blade.php once contained a JS comment with a literal `"`
     * inside it (from a `busy="submitting"` example in the comment text).
     * Since x-data is itself a double-quoted HTML attribute, that stray
     * quote closed the attribute early, leaking everything after it —
     * including the "New meeting" button — onto the page as broken markup
     * instead of rendering. Blade never throws for this, so a 200 status
     * code alone doesn't catch it; this is the same failure mode
     * room.blade.php already guards against (see MeetingRoomRenderTest).
     */
    public function test_home_page_x_data_attribute_is_not_truncated_by_a_stray_quote(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('New meeting', $html);

        $start = strpos($html, 'x-data="{ submitting') + strlen('x-data="');
        $end = strpos($html, '"', $start);
        $xData = substr($html, $start, $end - $start);

        $this->assertStringContainsString('submitting', $xData);
        $this->assertStringContainsString('$wire.create()', $xData);
        $this->assertSame(substr_count($xData, '{'), substr_count($xData, '}'));
    }
}
