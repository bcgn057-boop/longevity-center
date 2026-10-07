<?php

namespace App\Http\Resources;

use App\Models\EmergencyContact;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single place that decides which patient fields reach the browser.
 * Medical fields are omitted entirely (not nulled) for users who may not see them.
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewMedical = (bool) $request->user()?->can('viewMedical', $this->resource);

        return [
            'id' => $this->id,
            'medical_record_number' => $this->medical_record_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'sex' => $this->sex->value,
            'sex_label' => $this->sex->label(),
            'date_of_birth' => $this->date_of_birth,
            'age' => $this->age(),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'medical_history' => $this->when($canViewMedical, fn () => $this->medical_history),
            'allergies' => $this->when($canViewMedical, fn () => $this->allergies),
            'emergency_contacts' => $this->emergencyContacts->map(fn (EmergencyContact $contact) => [
                'name' => $contact->name,
                'relationship' => $contact->relationship,
                'phone' => $contact->phone,
                'email' => $contact->email,
            ])->all(),
            'account' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'archived' => $this->deleted_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
