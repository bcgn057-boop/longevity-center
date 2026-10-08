<?php

namespace Database\Factories;

use App\Models\CareReminder;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CareReminder>
 */
class CareReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory()->withAccount(),
            'title' => fake()->randomElement(['Book your follow-up blood test', 'Start your new supplement plan', 'Log your sleep this week']),
            'message' => fake()->optional()->sentence(10),
            'due_at' => now()->addDays(fake()->numberBetween(1, 30))->startOfHour(),
        ];
    }

    public function due(string $at): static
    {
        return $this->state(fn (array $attributes) => ['due_at' => $at]);
    }
}
