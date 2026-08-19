<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            // Bumped by a client-side heartbeat (see room.js) while a
            // participant's tab is open, and read by the scheduled
            // app:prune-stale-participants command to detect a tab that
            // closed/crashed without ever calling leave() — the "seen" half
            // of that pair; see ParticipantService::heartbeat()/pruneStale().
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
            $table->index(['left_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex(['left_at', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
