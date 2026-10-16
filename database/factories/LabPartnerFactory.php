<?php

namespace Database\Factories;

use App\Models\LabPartner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabPartner>
 */
class LabPartnerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Labs';

        return [
            'code' => Str::slug($name, '_'),
            'name' => $name,
            'tests' => [
                ['code' => 'blood_tests', 'label' => 'Blood tests'],
                ['code' => 'inflammatory_markers', 'label' => 'Inflammatory markers'],
            ],
        ];
    }
}
