<?php

namespace Database\Factories;

use App\Enums\BiomarkerCategory;
use App\Models\Biomarker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Biomarker>
 */
class BiomarkerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('marker_????'),
            'name' => fake()->words(2, true),
            'category' => fake()->randomElement(BiomarkerCategory::cases()),
            'units' => ['mg/dL', 'mmol/L'],
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
