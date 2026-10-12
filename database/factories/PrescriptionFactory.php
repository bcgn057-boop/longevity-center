<?php

namespace Database\Factories;

use App\Enums\PrescriptionStatus;
use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'prescribed_by' => User::factory()->withRole(UserRole::Doctor),
            'title' => fake()->randomElement(['Metabolic health protocol', 'Cardiovascular risk protocol', 'Sleep and recovery protocol']),
            'notes' => fake()->optional()->sentence(12),
            'starts_on' => now()->subDays(fake()->numberBetween(0, 30))->toDateString(),
            'ends_on' => null,
        ];
    }

    /**
     * A prescription already stopped by a doctor.
     */
    public function discontinued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PrescriptionStatus::Discontinued,
            'discontinued_at' => now(),
            'discontinued_reason' => 'Protocol completed',
        ]);
    }
}
