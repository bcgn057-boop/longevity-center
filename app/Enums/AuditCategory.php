<?php

namespace App\Enums;

/**
 * Groups of audit events for compliance review. Each event belongs to
 * exactly one group; see AuditEvent::category().
 */
enum AuditCategory: string
{
    case PhiAccess = 'phi_access';
    case PatientRecords = 'patient_records';
    case Appointments = 'appointments';
    case Labs = 'labs';
    case Prescriptions = 'prescriptions';
    case CareReminders = 'care_reminders';
    case Consent = 'consent';
    case Authentication = 'authentication';
    case Account = 'account';
    case Security = 'security';
    case Compliance = 'compliance';

    public function label(): string
    {
        return match ($this) {
            self::PhiAccess => 'PHI access (views, lists, searches)',
            self::PatientRecords => 'Patient record changes',
            self::Appointments => 'Appointment changes',
            self::Labs => 'Lab result changes',
            self::Prescriptions => 'Prescription changes',
            self::CareReminders => 'Care reminders',
            self::Consent => 'Notification consent',
            self::Authentication => 'Sign-in and authentication',
            self::Account => 'Account changes',
            self::Security => 'Access denied',
            self::Compliance => 'Audit and compliance access',
        };
    }
}
