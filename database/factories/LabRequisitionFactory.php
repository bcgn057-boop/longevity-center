<?php

namespace Database\Factories;

use App\Models\LabPartner;
use App\Models\LabRequisition;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabRequisition>
 */
class LabRequisitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'lab_partner_id' => LabPartner::factory(),
            'tests' => fn (array $attributes) => [LabPartner::query()->whereKey((int) $attributes['lab_partner_id'])->firstOrFail()->testCodes()[0]],
            'instructions' => null,
        ];
    }
}
