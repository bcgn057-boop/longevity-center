<?php

namespace App\Http\Requests\Patients;

use App\Models\Patient;

class StorePatientRequest extends PatientRequest
{
    protected function canWriteMedical(): bool
    {
        return (bool) $this->user()?->can('updateMedical', Patient::class);
    }
}
