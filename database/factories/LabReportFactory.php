<?php

namespace Database\Factories;

use App\Enums\LabReportStatus;
use App\Enums\UserRole;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabReport>
 */
class LabReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'collected_at' => now()->subDays(fake()->numberBetween(1, 60))->setTime(8, 0),
            'lab_name' => fake()->randomElement(['Quest Diagnostics', 'LabCorp', 'In-house lab']),
            'notes' => null,
        ];
    }

    /**
     * A report already reviewed and released to the patient.
     */
    public function released(?User $reviewer = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LabReportStatus::Released,
            'reviewed_by' => $reviewer->id ?? User::factory()->withRole(UserRole::Doctor),
            'reviewed_at' => now(),
        ]);
    }
}
