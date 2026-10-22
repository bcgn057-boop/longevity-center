<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PatientIntakeForm;
use App\Models\User;

/**
 * Doctors and practitioners send, fill in and review intake forms; a patient
 * sees and answers only their own. Listed in CLINICAL_ABILITIES, so the
 * Super Admin bypass never applies.
 */
class PatientIntakeFormPolicy
{
    public function manageIntake(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner);
    }

    public function viewIntake(User $user, PatientIntakeForm $form): bool
    {
        if ($user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner)) {
            return true;
        }

        return $user->hasRole(UserRole::Patient)
            && $form->patient->user_id !== null
            && $form->patient->user_id === $user->id;
    }
}
