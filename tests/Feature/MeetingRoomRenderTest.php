<?php

namespace Tests\Feature;

use App\Livewire\Meeting\Room;
use App\Models\Meeting;
use App\Services\ParticipantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression test for a real bug: a stray escaped quote inside the Room
 * component's x-data attribute (resources/views/livewire/meeting/room.blade.php)
 * broke Blade's directive-argument parsing, which silently left the rest of
 * the @js(...) directives in that attribute un-compiled — they leaked onto
 * the page as literal "@js(...)" text instead of executing, corrupting the
 * Alpine component's x-data JS beyond that point. A plain 200 status code
 * doesn't catch this, since Blade doesn't throw when this happens.
 */
class MeetingRoomRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_joined_room_view_does_not_leak_unprocessed_blade_directives(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}");

        $response->assertStatus(200);
        $response->assertDontSee('@js(', false);
        $response->assertSee('x-init="init()"', false);
    }

    public function test_joined_room_view_uses_svg_icons_not_emoji(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}");

        $response->assertSee('<svg', false);

        foreach (['🎤', '🔇', '📹', '🚫', '🖥️', '🔒', '🔓', '✕', '👥'] as $emoji) {
            $response->assertDontSee($emoji);
        }
    }

    /**
     * Generic guard against the whole class of bug behind two real
     * incidents: a stray literal `"` anywhere inside the room's x-data
     * attribute (even inside a JS comment) prematurely closes that
     * double-quoted HTML attribute, silently truncating it — everything
     * after leaks onto the page as plain text instead of being part of
     * the Alpine component. Blade never throws for this, so it has to be
     * checked for explicitly rather than relying on a 200 status code.
     *
     * The bulk of the component's logic now lives in
     * resources/js/webrtc/room-alpine.js (registered via Alpine.data()),
     * specifically because a real .js file can't suffer this failure mode
     * at all — there's no HTML attribute for a stray quote to break out
     * of. What's left inline is just the initial config object, still
     * worth guarding since it's still HTML-attribute text.
     */
    public function test_room_x_data_attribute_is_not_truncated_by_a_stray_quote(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $html = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}")->getContent();

        $start = strpos($html, 'x-data="meetingRoom({') + strlen('x-data="');
        $end = strpos($html, '"', $start);
        $xData = substr($html, $start, $end - $start);

        // Every config key passed into meetingRoom() must appear inside the
        // extracted value — if a stray quote cut it short, later ones
        // (isHost is passed last) would be missing.
        foreach (['meetingUuid', 'participantId', 'displayName', 'joinedAt', 'createdAt', 'isHost'] as $needle) {
            $this->assertStringContainsString($needle, $xData);
        }

        $this->assertStringContainsString('x-init="init()"', $html);
        $this->assertSame(substr_count($xData, '{'), substr_count($xData, '}'));
    }

    public function test_leave_and_end_meeting_use_call_specific_icons(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        $participant = app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');
        $participant->update(['is_host' => true]);

        $response = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}");

        // "Leave" uses the phone-hangup glyph, "End meeting" uses power —
        // a generic exit-door icon (the previous "logout" glyph) doesn't
        // read as "hang up" in a call UI.
        $response->assertSee('3.5 15.5c3-6 14-6 17 0', false);
        $response->assertSee('M12 3v7', false);
    }

    /**
     * Regression test for a real bug: the video grid's static class list
     * included `flex-none`, while its :class binding separately added
     * `flex-1` for the non-presenting case. Both set the same CSS `flex`
     * shorthand — having both present on one element is a genuine conflict
     * resolved by stylesheet order, not by which one is contextually
     * correct, and `flex-none` was winning. The visible symptom: the grid
     * sized itself to its content instead of filling <main>, so a lone
     * participant's tile rendered near the top with a large dead gap below
     * it instead of being vertically centered in the available space.
     */
    public function test_video_grid_has_no_conflicting_flex_utility_in_its_static_class(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $html = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}")->getContent();

        $start = strpos($html, 'x-ref="grid"');
        $classStart = strpos($html, 'class="', $start) + strlen('class="');
        $classEnd = strpos($html, '"', $classStart);
        $staticClass = substr($html, $classStart, $classEnd - $classStart);

        $this->assertStringNotContainsString('flex-none', $staticClass);
    }

    /**
     * Regression test for a real bug: the grid's immediate parent wrapper
     * had `items-center`, which stops it being stretched to a definite
     * width by its own flex parent. Per the CSS Grid spec, a grid
     * container with an *indefinite* size in an axis always resolves
     * `repeat(auto-fill, ...)` / `repeat(auto-fit, ...)` in that axis to
     * exactly 1 repetition — no matter how much space is actually
     * available. That silently forced every multi-column layout (the
     * 2-participant view, the presenting-mode thumbnail strip) into a
     * single stacked column with a scrollbar, even on a wide viewport.
     * The wrapper must stay stretched (no items-center); each child
     * centers itself internally instead (grid via its own
     * justify-center, the caption via text-center).
     */
    public function test_video_grid_wrapper_is_not_centered_via_flex_align_items(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $html = $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ])->get("/meet/{$meeting->uuid}")->getContent();

        // Note the leading space: `:class="` (Alpine's dynamic binding, which
        // this wrapper also has) contains "class=\"" as a substring too, so
        // searching for that alone would find the wrong attribute.
        $gridPos = strpos($html, 'x-ref="grid"');
        $beforeGrid = substr($html, 0, $gridPos);
        $classStart = strrpos($beforeGrid, ' class="') + strlen(' class="');
        $classEnd = strpos($html, '"', $classStart);
        $wrapperClass = substr($html, $classStart, $classEnd - $classStart);

        $this->assertStringContainsString('flex', $wrapperClass);
        $this->assertStringNotContainsString('items-center', $wrapperClass);
    }

    public function test_room_exposes_the_participants_real_join_time_for_the_list(): void
    {
        $meeting = Meeting::factory()->create();
        $participantId = (string) Str::uuid();

        $participant = app(ParticipantService::class)->join($meeting, $participantId, 'Debugger');

        $this->withSession([
            "meeting.{$meeting->uuid}.participant_id" => $participantId,
            "meeting.{$meeting->uuid}.display_name" => 'Debugger',
        ]);

        Livewire::test(Room::class, ['meeting' => $meeting])
            ->assertSet('joinedAt', $participant->joined_at->toIso8601String());
    }
}
