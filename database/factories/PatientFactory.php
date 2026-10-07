<?php

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\EmergencyContact;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'sex' => fake()->randomElement([Sex::Female, Sex::Male]),
            'date_of_birth' => fake()->dateTimeBetween('-85 years', '-18 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+1415555'.fake()->numerify('####'),
            'address' => fake()->address(),
            'medical_history' => fake()->sentence(12),
            'allergies' => fake()->randomElement([null, 'Penicillin', 'Peanuts', 'Latex']),
        ];
    }

    /**
     * Link the patient to a user account with the Patient role.
     */
    public function withAccount(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id ?? User::factory(),
        ]);
    }

    /**
     * Give the patient emergency contacts.
     */
    public function withEmergencyContacts(int $count = 1): static
    {
        return $this->has(EmergencyContact::factory()->count($count), 'emergencyContacts');
    }
}
