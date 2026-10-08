<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * Define the model's default state: a 30-minute slot during working hours
     * within the next two weeks.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::today('UTC')
            ->addDays(fake()->numberBetween(1, 14))
            ->setTime(fake()->numberBetween(9, 16), fake()->randomElement([0, 15, 30, 45]));

        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => User::factory()->withRole(UserRole::Doctor),
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes(30),
            'reason' => fake()->randomElement([null, 'Follow-up consultation', 'Annual review', 'Results discussion']),
        ];
    }

    /**
     * Book the appointment for the given time block.
     */
    public function at(DateTimeInterface|string $start, int $minutes = 30): static
    {
        $start = Carbon::parse($start);

        return $this->state(fn (array $attributes) => [
            'start_time' => $start,
            'end_time' => $start->copy()->addMinutes($minutes),
        ]);
    }

    /**
     * Mark the appointment as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Completed,
        ]);
    }

    /**
     * Mark the appointment as cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
