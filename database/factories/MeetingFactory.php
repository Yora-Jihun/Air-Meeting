<?php

namespace Database\Factories;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meeting>
 */
class MeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->boolean(60) ? fake()->sentence(3) : null,
            'status' => 'active',
            'is_locked' => false,
            'max_participants' => 12,
            'expires_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => 'ended', 'ended_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subHour()]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['is_locked' => true]);
    }

    public function withPassword(string $password = 'secret'): static
    {
        return $this->state(fn () => ['password' => bcrypt($password)]);
    }
}
