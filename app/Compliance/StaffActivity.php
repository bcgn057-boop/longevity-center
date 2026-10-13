<?php

namespace App\Compliance;

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What each staff member did in a period, counted from the audit log.
 * Counts only: which patients were involved is never shown here; use the
 * audit log, filtered by staff member, for that.
 */
final class StaffActivity
{
    /**
     * The columns of the report and the events each one counts.
     *
     * @return array<string, array{label: string, events: array<int, AuditEvent>}>
     */
    public static function columns(): array
    {
        return [
            'phi_access' => ['label' => 'Records accessed', 'events' => AuditEvent::inCategory(AuditCategory::PhiAccess)],
            'patient_views' => ['label' => 'Patient record views', 'events' => [AuditEvent::PatientViewed]],
            'patient_searches' => ['label' => 'Patient searches', 'events' => [AuditEvent::PatientSearched]],
            'prescriptions_issued' => ['label' => 'Prescriptions issued', 'events' => [AuditEvent::PrescriptionIssued]],
            'protocol_changes' => ['label' => 'Protocol changes', 'events' => [
                AuditEvent::PrescriptionUpdated, AuditEvent::PrescriptionDiscontinued,
                AuditEvent::ProtocolItemAdded, AuditEvent::ProtocolItemAdjusted, AuditEvent::ProtocolItemDiscontinued,
            ]],
            'lab_results_entered' => ['label' => 'Lab results entered', 'events' => [AuditEvent::LabResultRecorded, AuditEvent::LabResultCorrected]],
            'lab_reports_released' => ['label' => 'Lab reports released', 'events' => [AuditEvent::LabReportReleased]],
            'sign_ins' => ['label' => 'Sign-ins', 'events' => [AuditEvent::Login]],
            'access_denied' => ['label' => 'Access denied', 'events' => [AuditEvent::AccessDenied]],
        ];
    }

    /**
     * One row per staff account, busiest first by records accessed.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(ReportPeriod $period): array
    {
        $staff = User::query()
            ->with('role')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', array_map(fn (UserRole $role) => $role->value, UserRole::staff())))
            ->get();

        $counts = AuditLog::query()
            ->whereIn('user_id', $staff->modelKeys())
            ->where('created_at', '>=', $period->startsAt())
            ->where('created_at', '<', $period->endsBefore())
            ->selectRaw('user_id, event, count(*) as aggregate, max(created_at) as last_at')
            ->groupBy('user_id', 'event')
            ->toBase()
            ->get()
            ->groupBy('user_id');

        $columns = self::columns();

        return $staff
            ->map(function (User $user) use ($counts, $columns) {
                $rows = $counts->get($user->id, collect());
                $byEvent = $rows->pluck('aggregate', 'event');

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->slug->label(),
                    'last_active' => $rows->max('last_at') === null ? null : Carbon::parse($rows->max('last_at'), 'UTC')->toIso8601String(),
                    'counts' => array_map(
                        fn (array $column) => (int) collect($column['events'])->sum(fn (AuditEvent $event) => (int) ($byEvent[$event->value] ?? 0)),
                        $columns,
                    ),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => $b['counts']['phi_access'] <=> $a['counts']['phi_access'],
                fn (array $a, array $b) => strcmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }
}
