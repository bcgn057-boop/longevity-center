<?php

namespace App\Policies;

use App\Enums\AssessmentKind;
use App\Enums\UserRole;
use App\Models\User;

/**
 * Doctors and practitioners read and record every kind of assessment from
 * the clinical record. Patients record their own at-home measurements
 * through the portal, which checks ownership itself. Listed in
 * CLINICAL_ABILITIES, so the Super Admin bypass never applies.
 */
class FunctionalAssessmentPolicy
{
    public function viewAssessments(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner);
    }

    /**
     * At-center tests: clinicians only, whoever else may record at home.
     */
    public function recordAssessment(User $user, AssessmentKind $kind): bool
    {
        if ($user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner)) {
            return true;
        }

        return $kind === AssessmentKind::AtHome && $user->hasRole(UserRole::Patient);
    }
}
