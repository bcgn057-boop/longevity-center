<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case ClinicAdmin = 'clinic_admin';
    case Doctor = 'doctor';
    case Practitioner = 'practitioner';
    case LabStaff = 'lab_staff';
    case Patient = 'patient';
    case ComplianceOfficer = 'compliance_officer';

    /**
     * Get the human-readable name of the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::ClinicAdmin => 'Clinic Admin',
            self::Doctor => 'Doctor',
            self::Practitioner => 'Practitioner',
            self::LabStaff => 'Lab Staff',
            self::Patient => 'Patient',
            self::ComplianceOfficer => 'Compliance Officer',
        };
    }

    /**
     * Get the roles that belong to clinic staff (everyone except patients).
     *
     * @return array<int, self>
     */
    public static function staff(): array
    {
        return [
            self::SuperAdmin,
            self::ClinicAdmin,
            self::Doctor,
            self::Practitioner,
            self::LabStaff,
            self::ComplianceOfficer,
        ];
    }
}
