<?php

namespace Database\Factories;

use App\Enums\IntakeStatus;
use App\Models\Patient;
use App\Models\PatientIntakeForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientIntakeForm>
 */
class PatientIntakeFormFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'status' => IntakeStatus::Draft,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => IntakeStatus::Completed,
            'submitted_at' => now(),
            'lifestyle' => ['sleep_hours' => 7.5, 'stress' => 'moderate'],
            'conditions' => ['high_blood_pressure' => ['present' => true, 'age_at_diagnosis' => 48]],
        ]);
    }
}
