<?php

namespace Database\Factories;

use App\Models\Biomarker;
use App\Models\LabReport;
use App\Models\LabResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabResult>
 */
class LabResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lab_report_id' => LabReport::factory(),
            'biomarker_id' => Biomarker::factory(),
            'value' => (string) fake()->randomFloat(1, 1, 200),
            'unit' => fn (array $attributes) => Biomarker::query()->whereKey((int) $attributes['biomarker_id'])->firstOrFail()->units[0],
            'reference_low' => null,
            'reference_high' => null,
        ];
    }

    /**
     * Give the result the lab's printed reference range.
     */
    public function range(?string $low, ?string $high): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_low' => $low,
            'reference_high' => $high,
        ]);
    }
}
