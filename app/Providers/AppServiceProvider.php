<?php

namespace App\Providers;

use App\Models\Participant;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Signaling (SDP/ICE) needs the Reverb WebSocket server running
        // alongside the usual `php artisan serve` + Vite processes, so it
        // rides along with `composer run dev` / `php artisan dev` instead
        // of requiring a separate terminal.
        if ($this->app->environment('local')) {
            DevCommands::artisan('reverb:start --debug', 'reverb');
        }

        $this->registerParticipantGuard();
    }

    /**
     * Backs the "participant" guard (config/auth.php) used only by
     * routes/channels.php to satisfy PusherBroadcaster::auth()'s hard
     * requirement of an authenticated user for private/presence channels.
     * It resolves whichever meeting the /broadcasting/auth request's
     * channel_name refers to and checks the same session-based identity
     * App\Livewire\Meeting\Join establishes — no password, no login.
     */
    private function registerParticipantGuard(): void
    {
        Auth::viaRequest('participant', function ($request) {
            $uuid = Str::of((string) $request->input('channel_name'))->after('meeting.')->toString();

            $participantId = $uuid !== '' ? session("meeting.{$uuid}.participant_id") : null;

            if (! $participantId) {
                return null;
            }

            return Participant::query()
                ->whereHas('meeting', fn ($query) => $query->where('uuid', $uuid))
                ->where('participant_id', $participantId)
                ->whereNull('left_at')
                ->first();
        });
    }
}
