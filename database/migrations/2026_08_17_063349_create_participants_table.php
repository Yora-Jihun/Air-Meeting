<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            // Per-browser-tab identity generated client-side and kept in
            // sessionStorage. Doubles as the WebRTC peer id used in signaling.
            $table->uuid('participant_id');

            $table->string('display_name', 50);
            $table->boolean('is_host')->default(false);
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_camera_off')->default(false);

            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'participant_id']);
            $table->index(['meeting_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participants');
    }
};
