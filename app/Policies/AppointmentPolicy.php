<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

/**
 * Staff book and manage appointments; patients see and cancel their own.
 *
 * Super Admins pass every check here through Gate::before, except viewReason,
 * because the reason for a visit is clinical information: see AppServiceProvider.
 * Whether an appointment's status still allows a change is checked by the
 * controllers and the model, not here, so the Super Admin bypass cannot skip it.
 */
class AppointmentPolicy
{
    /**
     * Roles that may book and manage appointments.
     *
     * @var array<int, UserRole>
     */
    private const STAFF = [UserRole::ClinicAdmin, UserRole::Doctor, UserRole::Practitioner];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->hasAnyRole(...self::STAFF) || $this->isOwnAppointment($user, $appointment);
    }

    /**
     * Reading or writing the reason for the visit and for any cancellation.
     * Checked against the class when booking.
     */
    public function viewReason(User $user, ?Appointment $appointment = null): bool
    {
        return $user->hasAnyRole(...self::STAFF)
            || ($appointment !== null && $this->isOwnAppointment($user, $appointment));
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function complete(User $user, Appointment $appointment): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->hasAnyRole(...self::STAFF) || $this->isOwnAppointment($user, $appointment);
    }

    private function isOwnAppointment(User $user, Appointment $appointment): bool
    {
        $owner = $appointment->patient->user_id;

        return $user->hasRole(UserRole::Patient) && $owner !== null && $owner === $user->id;
    }
}
