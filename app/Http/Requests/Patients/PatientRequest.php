<?php

namespace App\Http\Requests\Patients;

use App\Enums\Sex;
use App\Models\Patient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for creating and updating patients.
 *
 * Medical fields are rejected outright (not silently dropped) for users who
 * may not write them, so a client cannot smuggle medical data in.
 */
abstract class PatientRequest extends FormRequest
{
    /**
     * International phone format (E.164): +, country code, up to 15 digits.
     */
    private const PHONE_PATTERN = '/^\+[1-9]\d{7,14}$/';

    /**
     * Determine if the user may write medical fields for this request.
     */
    abstract protected function canWriteMedical(): bool;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $medical = $this->canWriteMedical() ? ['nullable', 'string'] : ['prohibited'];

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'sex' => ['required', Rule::enum(Sex::class)],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.self::PHONE_PATTERN],
            'address' => ['nullable', 'string', 'max:500'],
            'medical_history' => [...$medical, 'max:10000'],
            'allergies' => [...$medical, 'max:2000'],

            'emergency_contacts' => ['nullable', 'array', 'list', 'max:3'],
            'emergency_contacts.*' => ['array:name,relationship,phone,email'],
            'emergency_contacts.*.name' => ['required', 'string', 'max:100'],
            'emergency_contacts.*.relationship' => ['required', 'string', 'max:50'],
            'emergency_contacts.*.phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'emergency_contacts.*.email' => ['nullable', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $phone = 'Enter the phone number in international format, e.g. +14155550123.';

        return [
            'phone.regex' => $phone,
            'emergency_contacts.*.phone.regex' => $phone,
            'medical_history.prohibited' => 'You are not allowed to edit medical information.',
            'allergies.prohibited' => 'You are not allowed to edit medical information.',
            'emergency_contacts.max' => 'A patient can have at most 3 emergency contacts.',
        ];
    }

    /**
     * Get the demographic (non-medical) patient attributes.
     *
     * @return array<string, mixed>
     */
    public function demographics(): array
    {
        return $this->safe()->only(['first_name', 'last_name', 'sex', 'date_of_birth', 'email', 'phone', 'address']);
    }

    /**
     * Get the medical attributes, or none if the user may not write them.
     *
     * @return array<string, mixed>
     */
    public function medical(): array
    {
        return $this->canWriteMedical() ? $this->safe()->only(Patient::MEDICAL_FIELDS) : [];
    }

    /**
     * Get the submitted emergency contacts, or null if they were not submitted.
     *
     * @return array<int, array{name: string, relationship: string, phone: string, email: string|null}>|null
     */
    public function emergencyContacts(): ?array
    {
        if (! $this->has('emergency_contacts')) {
            return null;
        }

        return array_map(fn (array $contact) => [
            'name' => $contact['name'],
            'relationship' => $contact['relationship'],
            'phone' => $contact['phone'],
            'email' => $contact['email'] ?? null,
        ], $this->validated('emergency_contacts') ?? []);
    }

    /**
     * Normalize phone numbers and trim names before validation.
     */
    protected function prepareForValidation(): void
    {
        $contacts = $this->input('emergency_contacts');

        $this->merge(array_filter([
            'phone' => $this->normalizePhone($this->input('phone')),
            'emergency_contacts' => is_array($contacts)
                ? array_map(
                    fn ($contact) => is_array($contact)
                        ? [...$contact, 'phone' => $this->normalizePhone($contact['phone'] ?? null)]
                        : $contact,
                    $contacts,
                )
                : $contacts,
        ], fn ($value) => $value !== null));
    }

    /**
     * Strip formatting characters and convert a leading 00 to +.
     */
    private function normalizePhone(mixed $phone): mixed
    {
        if (! is_string($phone)) {
            return $phone;
        }

        $phone = preg_replace('/[\s\-().]/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        return $phone === '' ? null : $phone;
    }
}
