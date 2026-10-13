<?php

namespace App\Compliance;

use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\CareReminder;
use App\Models\LabReport;
use App\Models\LabResult;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\ProtocolAdherence;
use App\Models\ProtocolItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The filters shared by the audit log viewer and its exports. Dates are
 * calendar days in the clinic's timezone.
 */
final class AuditLogFilter
{
    /**
     * @param  array<string, mixed>  $values  validated against rules()
     */
    private function __construct(private readonly array $values) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'category' => ['nullable', Rule::enum(AuditCategory::class)],
            'event' => ['nullable', 'string', Rule::enum(AuditEvent::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function from(array $validated): self
    {
        $values = [];

        foreach (array_keys(self::rules()) as $key) {
            if (filled($validated[$key] ?? null)) {
                $values[$key] = in_array($key, ['user_id', 'patient_id'], true) ? (int) $validated[$key] : (string) $validated[$key];
            }
        }

        return new self($values);
    }

    /**
     * The filters in use, for the page and for the audit entry recording the
     * view or export.
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function apply(Builder $query): Builder
    {
        $timezone = (string) config('clinic.timezone');

        return $query
            ->when($this->values['category'] ?? null, fn (Builder $query, string $category) => $query->whereIn(
                'event',
                array_map(fn (AuditEvent $event) => $event->value, AuditEvent::inCategory(AuditCategory::from($category))),
            ))
            ->when($this->values['event'] ?? null, fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($this->values['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($this->values['patient_id'] ?? null, fn (Builder $query, int $patientId) => $this->aboutPatient($query, $patientId))
            ->when($this->values['from'] ?? null, fn (Builder $query, string $from) => $query->where(
                'created_at',
                '>=',
                Carbon::createFromFormat('!Y-m-d', $from, $timezone)->utc()->format('Y-m-d H:i:s'),
            ))
            ->when($this->values['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at',
                '<',
                Carbon::createFromFormat('!Y-m-d', $to, $timezone)->addDay()->utc()->format('Y-m-d H:i:s'),
            ));
    }

    /**
     * Entries about the patient or any of their records, for an accounting
     * of who accessed or changed them.
     *
     * @param  Builder<AuditLog>  $query
     */
    private function aboutPatient(Builder $query, int $patientId): void
    {
        $owned = [
            Patient::class => fn (QueryBuilder $q) => $q->select('id')->from('patients')->where('id', $patientId),
            Appointment::class => fn (QueryBuilder $q) => $q->select('id')->from('appointments')->where('patient_id', $patientId),
            CareReminder::class => fn (QueryBuilder $q) => $q->select('id')->from('care_reminders')->where('patient_id', $patientId),
            LabReport::class => fn (QueryBuilder $q) => $q->select('id')->from('lab_reports')->where('patient_id', $patientId),
            LabResult::class => fn (QueryBuilder $q) => $q->select('lab_results.id')->from('lab_results')
                ->join('lab_reports', 'lab_reports.id', '=', 'lab_results.lab_report_id')
                ->where('lab_reports.patient_id', $patientId),
            Prescription::class => fn (QueryBuilder $q) => $q->select('id')->from('prescriptions')->where('patient_id', $patientId),
            ProtocolItem::class => fn (QueryBuilder $q) => $q->select('protocol_items.id')->from('protocol_items')
                ->join('prescriptions', 'prescriptions.id', '=', 'protocol_items.prescription_id')
                ->where('prescriptions.patient_id', $patientId),
            ProtocolAdherence::class => fn (QueryBuilder $q) => $q->select('id')->from('protocol_adherences')->where('patient_id', $patientId),
        ];

        $query->where(function (Builder $query) use ($owned, $patientId) {
            foreach ($owned as $type => $ids) {
                $query->orWhere(fn (Builder $query) => $query
                    ->where('auditable_type', (new $type)->getMorphClass())
                    ->whereIn('auditable_id', $ids));
            }

            // Events about a patient that record it in their metadata.
            $query->orWhere('metadata->patient_id', $patientId);
        });
    }
}
