<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();

            // Not a foreign key to participants: a chat row must outlive
            // the sender's participant row (which is deleted/reused across
            // rejoins under the same browser-tab identity), so the name is
            // snapshotted here rather than joined at read time.
            $table->uuid('participant_id');
            $table->string('display_name', 50);
            $table->string('message', 500);

            $table->timestamps();

            $table->index(['meeting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
