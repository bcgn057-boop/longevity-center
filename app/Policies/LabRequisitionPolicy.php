<?php

namespace App\Policies;

use App\Enums\LabRequisitionStatus;
use App\Enums\UserRole;
use App\Models\LabRequisition;
use App\Models\User;

/**
 * Clinicians order and cancel requisitions; each partner's lab users see
 * only the requisitions sent to their lab. Listed in CLINICAL_ABILITIES.
 */
class LabRequisitionPolicy
{
    public function viewLabRequisitions(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner)
            || ($user->hasRole(UserRole::LabStaff) && $user->lab_partner_id !== null);
    }

    public function viewLabRequisition(User $user, LabRequisition $requisition): bool
    {
        if ($user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner)) {
            return true;
        }

        return $user->hasRole(UserRole::LabStaff)
            && $user->lab_partner_id !== null
            && $requisition->lab_partner_id === $user->lab_partner_id;
    }

    public function orderLabTests(User $user): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner);
    }

    public function cancelLabRequisition(User $user, LabRequisition $requisition): bool
    {
        return $user->hasAnyRole(UserRole::Doctor, UserRole::Practitioner)
            && $requisition->status === LabRequisitionStatus::Ordered;
    }
}
