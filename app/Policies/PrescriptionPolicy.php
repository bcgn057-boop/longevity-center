<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Prescription;
use App\Models\User;

/**
 * Prescriptions are clinical data. Every ability here is listed in
 * AppServiceProvider::CLINICAL_ABILITIES, so the Super Admin bypass never
 * grants it. Doctors and practitioners can read prescriptions; only doctors
 * issue, adjust or stop them. Patients see their own through the portal.
 */
class PrescriptionPolicy
{
    public function viewPrescriptions(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner);
    }

    /**
     * Issuing a prescription (checked against the class) or changing one
     * still active.
     */
    public function managePrescriptions(User $user, ?Prescription $prescription = null): bool
    {
        return $user->hasRole(UserRole::Doctor) && ($prescription?->isActive() ?? true);
    }
}
