<?php

namespace App\Http\Requests\Patients;

use App\Models\Patient;

class UpdatePatientRequest extends PatientRequest
{
    protected function canWriteMedical(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && (bool) $this->user()?->can('updateMedical', $patient);
    }
}
