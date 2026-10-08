<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LabReport;
use App\Models\User;

/**
 * Lab results are clinical data. Every ability here has a lab-specific name
 * and is listed in AppServiceProvider::CLINICAL_ABILITIES, so the Super Admin
 * bypass never grants it. The Clinic Admin has no access either.
 */
class LabReportPolicy
{
    /**
     * Roles that may enter and read results.
     *
     * @var array<int, UserRole>
     */
    private const ENTRY = [UserRole::LabStaff, UserRole::Doctor, UserRole::Practitioner];

    /**
     * Roles that may review, release and correct released results.
     *
     * @var array<int, UserRole>
     */
    private const CLINICIANS = [UserRole::Doctor, UserRole::Practitioner];

    public function viewLabReports(User $user): bool
    {
        return $user->hasAnyRole(...self::ENTRY);
    }

    /**
     * Staff who enter results see every report; a patient sees their own
     * once it is released.
     */
    public function viewLabReport(User $user, LabReport $report): bool
    {
        if ($user->hasAnyRole(...self::ENTRY)) {
            return true;
        }

        return $user->hasRole(UserRole::Patient)
            && $report->isReleased()
            && $report->patient->user_id !== null
            && $report->patient->user_id === $user->id;
    }

    /**
     * Creating a report (checked against the class) or adding results to one
     * still under review.
     */
    public function enterLabResults(User $user, ?LabReport $report = null): bool
    {
        return $user->hasAnyRole(...self::ENTRY) && ! ($report?->isReleased() ?? false);
    }

    /**
     * Before release, whoever enters results can correct them; after
     * release, only a doctor or practitioner can.
     */
    public function correctLabResults(User $user, LabReport $report): bool
    {
        return $user->hasAnyRole(...($report->isReleased() ? self::CLINICIANS : self::ENTRY));
    }

    public function releaseLabReport(User $user, LabReport $report): bool
    {
        return $user->hasAnyRole(...self::CLINICIANS) && ! $report->isReleased();
    }
}
