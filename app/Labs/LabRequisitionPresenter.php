<?php

namespace App\Labs;

use App\Models\LabRequisition;

/**
 * A requisition as clinicians and partner labs see it. The instructions are
 * for the lab, so both see them.
 */
final class LabRequisitionPresenter
{
    /**
     * @param  LabRequisition  $requisition  with patient and labPartner loaded
     * @return array<string, mixed>
     */
    public static function row(LabRequisition $requisition): array
    {
        $timezone = (string) config('clinic.timezone');

        return [
            'id' => $requisition->id,
            'patient' => [
                'id' => $requisition->patient->id,
                'full_name' => $requisition->patient->full_name,
                'medical_record_number' => $requisition->patient->medical_record_number,
            ],
            'lab_partner' => ['id' => $requisition->labPartner->id, 'name' => $requisition->labPartner->name],
            'tests' => $requisition->testLabels(),
            'instructions' => $requisition->instructions,
            'status' => $requisition->status->value,
            'status_label' => $requisition->status->label(),
            'ordered_by' => $requisition->orderer?->name,
            'ordered_at' => $requisition->created_at?->copy()->setTimezone($timezone)->format('D j M Y H:i'),
        ];
    }
}
