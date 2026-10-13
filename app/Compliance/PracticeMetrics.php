<?php

namespace App\Compliance;

use App\Enums\AppointmentStatus;
use App\Enums\LabReportStatus;
use App\Enums\ProtocolFrequency;
use App\Models\Appointment;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use Illuminate\Support\Carbon;

/**
 * Clinic-wide figures for a period. Counts and rates only: nothing here
 * names a patient or reveals one patient's records.
 */
final class PracticeMetrics
{
    /**
     * Below this many patients, an adherence group's size is not shown, so a
     * small group cannot be matched to individuals.
     */
    public const MIN_GROUP_SIZE = 5;

    /**
     * @return array<string, mixed>
     */
    public static function for(ReportPeriod $period): array
    {
        return [
            'patients' => self::patients($period),
            'appointments' => self::appointments($period),
            'prescriptions' => self::prescriptions($period),
            'adherence' => self::adherence($period),
            'labs' => self::labs($period),
        ];
    }

    /**
     * @return array{active: int, with_portal: int, new: int, archived: int}
     */
    private static function patients(ReportPeriod $period): array
    {
        return [
            'active' => Patient::query()->count(),
            'with_portal' => Patient::query()->whereNotNull('user_id')->count(),
            'new' => Patient::withTrashed()->where('created_at', '>=', $period->startsAt())->where('created_at', '<', $period->endsBefore())->count(),
            'archived' => Patient::onlyTrashed()->count(),
        ];
    }

    /**
     * Appointments starting in the period, by status.
     *
     * @return array{total: int, by_status: array<int, array{status: string, label: string, count: int}>}
     */
    private static function appointments(ReportPeriod $period): array
    {
        $counts = Appointment::query()
            ->where('start_time', '>=', $period->startsAt())
            ->where('start_time', '<', $period->endsBefore())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'by_status' => array_map(fn (AppointmentStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($counts[$status->value] ?? 0),
            ], AppointmentStatus::cases()),
        ];
    }

    /**
     * @return array{in_effect: int, patients_on_protocol: int, issued: int}
     */
    private static function prescriptions(ReportPeriod $period): array
    {
        return [
            'in_effect' => Prescription::query()->inEffect()->count(),
            'patients_on_protocol' => Prescription::query()->inEffect()->distinct()->count('patient_id'),
            'issued' => Prescription::query()->where('created_at', '>=', $period->startsAt())->where('created_at', '<', $period->endsBefore())->count(),
        ];
    }

    /**
     * Days ticked against days scheduled, for items patients tick daily, up
     * to today. Patients are grouped by their own rate; groups smaller than
     * MIN_GROUP_SIZE are shown as null.
     *
     * @return array{taken: int, scheduled: int, rate: float|null, patients: int, groups: array<int, array{label: string, patients: int|null}>}
     */
    private static function adherence(ReportPeriod $period): array
    {
        $timezone = (string) config('clinic.timezone');
        $last = min($period->to, ReportPeriod::today());
        $daily = array_map(fn (ProtocolFrequency $f) => $f->value, array_filter(ProtocolFrequency::cases(), fn (ProtocolFrequency $f) => $f->isDaily()));

        $items = ProtocolItem::query()
            ->with('prescription:id,patient_id,starts_on,ends_on')
            ->whereIn('frequency', $daily)
            ->where('created_at', '<', $period->endsBefore())
            ->where(fn ($query) => $query->whereNull('discontinued_at')->orWhere('discontinued_at', '>=', $period->startsAt()))
            ->get();

        $scheduled = [];

        foreach ($items as $item) {
            $prescription = $item->prescription;
            $start = max($period->from, $prescription->starts_on->toDateString(), $item->created_at?->setTimezone($timezone)->toDateString() ?? $period->from);
            $end = min(
                $last,
                $prescription->ends_on?->toDateString() ?? $last,
                // An item stopped on a day is no longer scheduled that day.
                $item->discontinued_at?->setTimezone($timezone)->subDay()->toDateString() ?? $last,
            );

            if ($start <= $end) {
                $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
                $scheduled[$prescription->patient_id] = ($scheduled[$prescription->patient_id] ?? 0) + $days;
            }
        }

        $taken = ProtocolAdherence::query()
            ->where('taken', true)
            ->whereIn('protocol_item_id', $items->modelKeys())
            ->whereDate('scheduled_date', '>=', $period->from)
            ->whereDate('scheduled_date', '<=', $last)
            ->selectRaw('patient_id, count(*) as aggregate')
            ->groupBy('patient_id')
            ->pluck('aggregate', 'patient_id');

        $groups = ['80% or more' => 0, '50–79%' => 0, 'Under 50%' => 0];

        foreach ($scheduled as $patientId => $days) {
            $rate = min(1, (int) ($taken[$patientId] ?? 0) / $days);
            $groups[$rate >= 0.8 ? '80% or more' : ($rate >= 0.5 ? '50–79%' : 'Under 50%')]++;
        }

        $totalScheduled = array_sum($scheduled);
        $totalTaken = (int) $taken->sum();

        return [
            'taken' => $totalTaken,
            'scheduled' => $totalScheduled,
            'rate' => $totalScheduled === 0 ? null : round(min(1, $totalTaken / $totalScheduled) * 100, 1),
            'patients' => count($scheduled),
            'groups' => array_map(
                fn (string $label, int $count) => ['label' => $label, 'patients' => $count >= self::MIN_GROUP_SIZE ? $count : null],
                array_keys($groups),
                $groups,
            ),
        ];
    }

    /**
     * @return array{pending_review: int, released: int, average_review_hours: float|null}
     */
    private static function labs(ReportPeriod $period): array
    {
        $released = LabReport::query()
            ->where('status', LabReportStatus::Released)
            ->where('reviewed_at', '>=', $period->startsAt())
            ->where('reviewed_at', '<', $period->endsBefore())
            ->get(['id', 'created_at', 'reviewed_at']);

        $hours = $released
            ->filter(fn (LabReport $report) => $report->created_at !== null && $report->reviewed_at !== null)
            ->map(fn (LabReport $report) => $report->created_at->diffInMinutes($report->reviewed_at) / 60);

        return [
            'pending_review' => LabReport::query()->where('status', LabReportStatus::PendingReview)->count(),
            'released' => $released->count(),
            'average_review_hours' => $hours->isEmpty() ? null : round((float) $hours->avg(), 1),
        ];
    }
}
