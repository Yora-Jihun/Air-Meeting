<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\Participant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Participant>
 */
class ParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'participant_id' => (string) Str::uuid(),
            'display_name' => fake()->firstName(),
            'is_host' => false,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(fn () => ['left_at' => now()]);
    }
}
