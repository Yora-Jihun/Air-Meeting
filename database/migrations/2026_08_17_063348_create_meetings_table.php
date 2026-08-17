<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 100)->nullable();
            $table->enum('status', ['active', 'ended'])->default('active')->index();

            // Anonymous "ownership": whoever holds this token created the meeting
            // and can perform host actions (lock, kick, end). Issued once at
            // creation time and stored client-side; never derived from auth.
            $table->uuid('host_token');

            $table->string('password')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->unsignedSmallInteger('max_participants')->default(12);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
