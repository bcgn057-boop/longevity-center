<?php

namespace App\Dashboard;

use App\Compliance\ReportPeriod;
use App\Enums\AppointmentStatus;
use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Enums\LabReportStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\LabReport;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The home dashboard for one user. Each section and action appears only if
 * the user holds the ability behind it, so the dashboard never links to a
 * page that would refuse them.
 *
 * Counts only: no patient is named here, so the dashboard can be left on
 * screen and is not itself a disclosure. The pages it links to show (and
 * audit) the details.
 */
final class DashboardData
{
    /**
     * Days covered by the compliance summary.
     */
    public const COMPLIANCE_DAYS = 7;

    /**
     * Days covered by the trend charts, ending today.
     */
    public const TREND_DAYS = 7;

    /**
     * @var array<int, AuditEvent>
     */
    private const FAILED_SIGN_IN = [AuditEvent::LoginFailed, AuditEvent::Lockout, AuditEvent::TwoFactorFailed];

    private readonly string $today;

    private readonly string $timezone;

    private function __construct(private readonly User $user)
    {
        $this->timezone = (string) config('clinic.timezone');
        $this->today = now($this->timezone)->toDateString();
    }

    /**
     * @return array{sections: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>, notice: string|null}
     */
    public static function for(User $user): array
    {
        $dashboard = new self($user);

        $sections = array_values(array_filter([
            $dashboard->patientSection(),
            $dashboard->clinicianSection(),
            $dashboard->labSection(),
            $dashboard->clinicSection(),
            $dashboard->complianceSection(),
        ]));

        return [
            'sections' => $sections,
            'actions' => $dashboard->actions(),
            'notice' => $user->role === null
                ? 'Your account has not been given a role yet. Ask a clinic administrator to set one up.'
                : null,
        ];
    }

    /**
     * A patient's own day: protocol items, appointments and results.
     *
     * @return array<string, mixed>|null
     */
    private function patientSection(): ?array
    {
        if (! $this->user->can('access-patient-portal') || $this->user->can('viewAny', Patient::class)) {
            return null;
        }

        $patient = $this->user->patient;

        if (! $patient instanceof Patient) {
            return [
                'title' => 'Your care',
                'cards' => [],
                'empty' => 'Your account is not linked to a patient record yet. Please contact the clinic.',
            ];
        }

        $items = ProtocolItem::query()
            ->current()
            ->whereHas('prescription', fn ($query) => $query->where('patient_id', $patient->id)->inEffect())
            ->with(['prescription', 'adherences' => fn ($query) => $query->whereDate('scheduled_date', $this->today)])
            ->get()
            ->filter(fn (ProtocolItem $item) => $item->isScheduledOn($this->today));
        $done = $items->filter(fn (ProtocolItem $item) => (bool) $item->adherences->first()?->taken)->count();

        $upcoming = Appointment::query()
            ->forPatient($patient)
            ->where('status', AppointmentStatus::Scheduled)
            ->where('start_time', '>=', now()->utc()->format('Y-m-d H:i:s'))
            ->orderBy('start_time');
        $next = (clone $upcoming)->first();

        $released = $patient->labReports()->where('status', LabReportStatus::Released);
        $latest = (clone $released)->max('reviewed_at');

        return [
            'title' => 'Your care',
            'cards' => [
                $this->card(
                    'protocol', 'Protocol items done today',
                    $items->isEmpty() ? '—' : "{$done} of {$items->count()}",
                    $items->isEmpty() ? 'Nothing to tick off today' : ($done === $items->count() ? 'All done for today' : 'Tick them off as you go'),
                    route('my-protocols.index'),
                ),
                $this->card(
                    'appointments', 'Upcoming appointments', (clone $upcoming)->count(),
                    $next ? 'Next: '.$next->start_time->setTimezone($this->timezone)->format('D j M, H:i') : 'None booked',
                    route('my-appointments.index'),
                ),
                $this->card(
                    'results', 'Lab reports', (clone $released)->count(),
                    $latest ? 'Latest released '.Carbon::parse($latest, 'UTC')->setTimezone($this->timezone)->format('j M Y') : 'None released yet',
                    route('my-results.index'),
                ),
            ],
        ];
    }

    /**
     * A doctor's or practitioner's own day.
     *
     * @return array<string, mixed>|null
     */
    private function clinicianSection(): ?array
    {
        if (! $this->user->can('access-clinical')) {
            return null;
        }

        $mine = $this->appointmentsToday()->forDoctor($this->user);

        return [
            'title' => 'Your day',
            'cards' => [
                $this->card(
                    'appointments', 'Your appointments today', (clone $mine)->where('status', AppointmentStatus::Scheduled)->count(),
                    ((clone $mine)->where('status', AppointmentStatus::Completed)->count()).' completed so far',
                    route('appointments.index', ['doctor_id' => $this->user->id, 'from' => $this->today, 'to' => $this->today]),
                ),
                $this->card(
                    'labs', 'Lab reports to review', LabReport::query()->where('status', LabReportStatus::PendingReview)->count(),
                    'Waiting for a doctor or practitioner',
                    route('labs.index'),
                ),
            ],
        ];
    }

    /**
     * Lab staff: the queue they feed.
     *
     * @return array<string, mixed>|null
     */
    private function labSection(): ?array
    {
        if (! $this->user->can('enterLabResults', LabReport::class) || $this->user->can('access-clinical')) {
            return null;
        }

        [$start, $end] = $this->todayBounds();

        return [
            'title' => 'Lab work',
            'cards' => [
                $this->card(
                    'labs', 'Entered by you today',
                    LabReport::query()->where('entered_by', $this->user->id)->where('created_at', '>=', $start)->where('created_at', '<', $end)->count(),
                    'Lab reports',
                    route('labs.index'),
                ),
                $this->card(
                    'review', 'Waiting for review', LabReport::query()->where('status', LabReportStatus::PendingReview)->count(),
                    'Across the clinic',
                    route('labs.index', ['status' => LabReportStatus::PendingReview->value]),
                ),
            ],
        ];
    }

    /**
     * The clinic as a whole, for anyone who works with patient records.
     *
     * @return array<string, mixed>|null
     */
    private function clinicSection(): ?array
    {
        if (! $this->user->can('viewAny', Patient::class)) {
            return null;
        }

        $today = $this->appointmentsToday();
        $weekAgo = Carbon::parse($this->today, $this->timezone)->subDays(6)->utc()->format('Y-m-d H:i:s');

        return [
            'title' => 'Clinic today',
            'cards' => [
                $this->card(
                    'patients', 'Active patients', Patient::query()->count(),
                    Patient::query()->where('created_at', '>=', $weekAgo)->count().' new in the last 7 days',
                    route('patients.index'),
                ),
                $this->card(
                    'appointments', 'Appointments today', (clone $today)->where('status', AppointmentStatus::Scheduled)->count(),
                    (clone $today)->where('status', AppointmentStatus::Cancelled)->count().' cancelled',
                    $this->user->can('viewAny', Appointment::class) ? route('appointments.index', ['from' => $this->today, 'to' => $this->today]) : null,
                ),
                $this->card(
                    'protocols', 'Prescriptions in effect', Prescription::query()->inEffect()->count(),
                    Prescription::query()->inEffect()->distinct()->count('patient_id').' patients on a protocol',
                    $this->user->can('viewPracticeAnalytics') ? route('compliance.analytics') : null,
                ),
            ],
            'charts' => [
                $this->chart('appointments', 'Appointments', 'appointments', fn (string $start, string $end) => Appointment::query()
                    ->where('start_time', '>=', $start)->where('start_time', '<', $end)
                    ->where('status', '!=', AppointmentStatus::Cancelled)
                    ->count()),
                $this->chart('patients', 'New patients', 'patients', fn (string $start, string $end) => Patient::withTrashed()
                    ->where('created_at', '>=', $start)->where('created_at', '<', $end)
                    ->count()),
            ],
        ];
    }

    /**
     * Counts of recent audit events, linking to the matching entries.
     *
     * @return array<string, mixed>|null
     */
    private function complianceSection(): ?array
    {
        if (! $this->user->can('viewAuditLogs')) {
            return null;
        }

        $period = ReportPeriod::of(
            Carbon::parse($this->today)->subDays(self::COMPLIANCE_DAYS - 1)->toDateString(),
            $this->today,
        );
        $recent = fn () => AuditLog::query()->where('created_at', '>=', $period->startsAt())->where('created_at', '<', $period->endsBefore());
        $link = fn (array $filters) => route('compliance.audit-logs.index', [...$filters, 'from' => $period->from, 'to' => $period->to]);

        return [
            'title' => 'Compliance: last '.self::COMPLIANCE_DAYS.' days',
            'cards' => [
                $this->card(
                    'phi', 'PHI access events',
                    $recent()->whereIn('event', AuditEvent::inCategory(AuditCategory::PhiAccess))->count(),
                    'Views, lists and searches of patient data',
                    $link(['category' => AuditCategory::PhiAccess->value]),
                ),
                $this->card(
                    'denied', 'Access denied', $recent()->where('event', AuditEvent::AccessDenied)->count(),
                    'Requests refused by role or permission',
                    $link(['category' => AuditCategory::Security->value]),
                ),
                $this->card(
                    'sign_in', 'Failed sign-ins',
                    $recent()->whereIn('event', self::FAILED_SIGN_IN)->count(),
                    'Wrong passwords, lockouts and 2FA failures',
                    $link(['category' => AuditCategory::Authentication->value]),
                ),
                $this->card(
                    'exports', 'Audit log exports', $recent()->where('event', AuditEvent::AuditLogExported)->count(),
                    'By anyone',
                    $link(['event' => AuditEvent::AuditLogExported->value]),
                ),
            ],
            'charts' => [
                $this->auditChart('phi', 'PHI access', AuditEvent::inCategory(AuditCategory::PhiAccess)),
                $this->auditChart('sign_in', 'Failed sign-ins', self::FAILED_SIGN_IN),
                $this->auditChart('denied', 'Access denied', [AuditEvent::AccessDenied]),
            ],
        ];
    }

    /**
     * Shortcuts to the main sections the user can open.
     *
     * @return array<int, array{key: string, label: string, description: string, href: string, download: bool}>
     */
    private function actions(): array
    {
        $user = $this->user;
        $from = Carbon::parse($this->today)->subDays(self::COMPLIANCE_DAYS - 1)->toDateString();

        return array_values(array_filter([
            $user->can('viewAny', Patient::class) ? $this->action('patients', 'Find a patient', 'Search by name or record number', route('patients.index')) : null,
            $user->can('create', Appointment::class) ? $this->action('book', 'Book an appointment', 'Schedule a visit', route('appointments.create')) : null,
            $user->can('viewAny', Appointment::class) ? $this->action('appointments', 'Appointments', 'The week ahead', route('appointments.index')) : null,
            $user->can('enterLabResults', LabReport::class) ? $this->action('lab_entry', 'Enter lab results', 'Record a new report', route('labs.create')) : null,
            $user->can('viewLabReports', LabReport::class) ? $this->action('labs', 'Lab results', $user->can('access-clinical') ? 'Your review queue' : 'All reports', route('labs.index')) : null,
            $user->can('access-patient-portal') && ! $user->can('viewAny', Patient::class) ? $this->action('protocol', 'My protocols', 'Tick off today\'s items', route('my-protocols.index')) : null,
            $user->can('access-patient-portal') && ! $user->can('viewAny', Patient::class) ? $this->action('results', 'My results', 'Your released lab reports', route('my-results.index')) : null,
            $user->can('access-patient-portal') && ! $user->can('viewAny', Patient::class) ? $this->action('appointments', 'My appointments', 'Upcoming visits', route('my-appointments.index')) : null,
            $user->can('viewPracticeAnalytics') ? $this->action('analytics', 'Practice analytics', 'Clinic-wide figures', route('compliance.analytics')) : null,
            $user->can('viewStaffActivity') ? $this->action('staff', 'Staff activity', 'Who did what, by count', route('compliance.staff-activity')) : null,
            $user->can('viewAuditLogs') ? $this->action('audit', 'Audit log', 'Search every recorded event', route('compliance.audit-logs.index')) : null,
            $user->can('exportAuditLogs') ? $this->action(
                'export', 'Export last '.self::COMPLIANCE_DAYS.' days', 'Audit log as CSV (recorded in the log)',
                route('compliance.audit-logs.export', ['format' => 'csv', 'from' => $from, 'to' => $this->today]),
                download: true,
            ) : null,
            $user->can('access-admin') ? $this->action('admin', 'Administration', 'Clinic settings', route('admin.dashboard')) : null,
        ]));
    }

    /**
     * Daily counts for the last TREND_DAYS days, oldest first. Each day is a
     * calendar day in the clinic's timezone, counted between its UTC bounds.
     *
     * @param  callable(string, string): int  $count  given a day's UTC start and end
     * @return array{key: string, title: string, unit: string, total: int, points: array<int, array{date: string, value: int}>}
     */
    private function chart(string $key, string $title, string $unit, callable $count): array
    {
        $today = Carbon::parse($this->today);
        $points = [];

        for ($back = self::TREND_DAYS - 1; $back >= 0; $back--) {
            $day = ReportPeriod::of($date = $today->copy()->subDays($back)->toDateString(), $date);
            $points[] = ['date' => $date, 'value' => $count($day->startsAt(), $day->endsBefore())];
        }

        return [
            'key' => $key,
            'title' => $title,
            'unit' => $unit,
            'total' => array_sum(array_column($points, 'value')),
            'points' => $points,
        ];
    }

    /**
     * @param  array<int, AuditEvent>  $events
     * @return array{key: string, title: string, unit: string, total: int, points: array<int, array{date: string, value: int}>}
     */
    private function auditChart(string $key, string $title, array $events): array
    {
        return $this->chart($key, $title, 'events', fn (string $start, string $end) => AuditLog::query()
            ->whereIn('event', $events)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->count());
    }

    /**
     * Today's appointments across the clinic.
     *
     * @return Builder<Appointment>
     */
    private function appointmentsToday(): Builder
    {
        [$start, $end] = $this->todayBounds();

        return Appointment::query()->where('start_time', '>=', $start)->where('start_time', '<', $end);
    }

    /**
     * Today in the clinic's timezone, as stored UTC bounds.
     *
     * @return array{0: string, 1: string}
     */
    private function todayBounds(): array
    {
        $day = ReportPeriod::of($this->today, $this->today);

        return [$day->startsAt(), $day->endsBefore()];
    }

    /**
     * @return array{key: string, label: string, value: int|string, detail: string, href: string|null}
     */
    private function card(string $key, string $label, int|string $value, string $detail, ?string $href): array
    {
        return compact('key', 'label', 'value', 'detail', 'href');
    }

    /**
     * @return array{key: string, label: string, description: string, href: string, download: bool}
     */
    private function action(string $key, string $label, string $description, string $href, bool $download = false): array
    {
        return compact('key', 'label', 'description', 'href', 'download');
    }
}
