<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;

/**
 * Super Admins pass every check here through Gate::before, except the
 * clinical abilities (viewMedical, updateMedical): see AppServiceProvider.
 */
class PatientPolicy
{
    /**
     * Roles that may work with patient records.
     *
     * @var array<int, UserRole>
     */
    private const STAFF = [UserRole::ClinicAdmin, UserRole::Doctor, UserRole::Practitioner];

    /**
     * Roles that may read and write medical information.
     *
     * @var array<int, UserRole>
     */
    private const CLINICIANS = [UserRole::Doctor, UserRole::Practitioner];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->hasAnyRole(...self::STAFF) || $this->isOwnRecord($user, $patient);
    }

    public function viewMedical(User $user, Patient $patient): bool
    {
        return $user->hasAnyRole(...self::CLINICIANS) || $this->isOwnRecord($user, $patient);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    /**
     * Writing medical fields. Checked against the class when creating a patient.
     */
    public function updateMedical(User $user, ?Patient $patient = null): bool
    {
        return $user->hasAnyRole(...self::CLINICIANS);
    }

    public function archive(User $user, Patient $patient): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin);
    }

    public function linkAccount(User $user, Patient $patient): bool
    {
        return $user->hasRole(UserRole::ClinicAdmin);
    }

    /**
     * Recording consent to email and WhatsApp reminders on the patient's behalf.
     */
    public function manageConsent(User $user, Patient $patient): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    /**
     * Writing and reading care reminders. Not granted to the Super Admin by
     * Gate::before, because the text may be clinical: see AppServiceProvider.
     */
    public function manageCareReminders(User $user, Patient $patient): bool
    {
        return $user->hasAnyRole(...self::STAFF);
    }

    private function isOwnRecord(User $user, Patient $patient): bool
    {
        return $user->hasRole(UserRole::Patient)
            && $patient->user_id !== null
            && $patient->user_id === $user->id;
    }
}
