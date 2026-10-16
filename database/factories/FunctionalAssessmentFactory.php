<?php

namespace Database\Factories;

use App\Enums\AssessmentKind;
use App\Models\FunctionalAssessment;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FunctionalAssessment>
 */
class FunctionalAssessmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'kind' => AssessmentKind::AtHome,
            'measured_on' => now()->toDateString(),
            'measurements' => ['height_cm' => 172.0, 'weight_kg' => 70.5],
            'recorded_via' => FunctionalAssessment::VIA_PORTAL,
        ];
    }

    public function atCenter(): static
    {
        return $this->state(fn () => [
            'kind' => AssessmentKind::AtCenter,
            'measurements' => ['grip_left_kg' => 32.0, 'grip_right_kg' => 35.5, 'chair_stand_reps' => 14, 'walk_6min_m' => 520],
            'recorded_via' => FunctionalAssessment::VIA_CLINIC,
        ]);
    }
}
