<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LabReport;
use App\Models\User;

/**
 * Lab results are clinical data. Every ability here has a lab-specific name
 * and is listed in AppServiceProvider::CLINICAL_ABILITIES, so the Super Admin
 * bypass never grants it. The Clinic Admin has no access either.
 *
 * Lab users work for one external partner and only reach that partner's
 * reports; a lab user without a partner reaches none. Doctors and
 * practitioners see every report.
 */
class LabReportPolicy
{
    /**
     * Roles that may review, release and correct released results.
     *
     * @var array<int, UserRole>
     */
    private const CLINICIANS = [UserRole::Doctor, UserRole::Practitioner];

    public function viewLabReports(User $user): bool
    {
        return $user->hasAnyRole(...self::CLINICIANS) || $this->isPartnerLabUser($user);
    }

    /**
     * Clinicians see every report and lab users their partner's; a patient
     * sees their own once it is released.
     */
    public function viewLabReport(User $user, LabReport $report): bool
    {
        if ($user->hasAnyRole(...self::CLINICIANS)) {
            return true;
        }

        if ($user->hasRole(UserRole::LabStaff)) {
            return $this->sameLab($user, $report);
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
        if ($report?->isReleased()) {
            return false;
        }

        if ($user->hasAnyRole(...self::CLINICIANS)) {
            return true;
        }

        return $report === null ? $this->isPartnerLabUser($user) : $this->sameLab($user, $report);
    }

    /**
     * Before release, whoever enters results can correct them; after
     * release, only a doctor or practitioner can.
     */
    public function correctLabResults(User $user, LabReport $report): bool
    {
        if ($user->hasAnyRole(...self::CLINICIANS)) {
            return true;
        }

        return ! $report->isReleased() && $this->sameLab($user, $report);
    }

    public function releaseLabReport(User $user, LabReport $report): bool
    {
        return $user->hasAnyRole(...self::CLINICIANS) && ! $report->isReleased();
    }

    private function isPartnerLabUser(User $user): bool
    {
        return $user->hasRole(UserRole::LabStaff) && $user->lab_partner_id !== null;
    }

    private function sameLab(User $user, LabReport $report): bool
    {
        return $this->isPartnerLabUser($user) && $report->lab_partner_id === $user->lab_partner_id;
    }
}
