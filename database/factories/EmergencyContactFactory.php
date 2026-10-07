<?php

namespace Database\Factories;

use App\Models\EmergencyContact;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmergencyContact>
 */
class EmergencyContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Friend']),
            'phone' => '+1415555'.fake()->numerify('####'),
            'email' => fake()->safeEmail(),
        ];
    }
}
