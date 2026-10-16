<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An account's details as an administrator enters them. Lab users must
 * belong to a lab partner; nobody else may.
 */
class UserRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => $account instanceof User
                ? ['prohibited']
                : ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'lab_partner_id' => [
                Rule::requiredIf($this->input('role') === UserRole::LabStaff->value),
                Rule::prohibitedIf($this->input('role') !== UserRole::LabStaff->value && filled($this->input('lab_partner_id'))),
                'nullable',
                'integer',
                Rule::exists('lab_partners', 'id')->where('active', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lab_partner_id.required' => 'Choose the lab partner this lab user works for.',
            'lab_partner_id.prohibited' => 'Only lab users belong to a lab partner.',
            'email.prohibited' => 'The email address cannot be changed here.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $role = UserRole::tryFrom((string) $this->input('role'));

            if ($role !== null && ! $this->user()?->can('assignRole', [User::class, $role])) {
                $validator->errors()->add('role', 'You cannot give accounts this role.');
            }
        }];
    }

    /**
     * @return array{name: string, email: string, role: UserRole, lab_partner_id: int|null}
     */
    public function details(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'email' => (string) $this->validated('email'),
            'role' => UserRole::from((string) $this->validated('role')),
            'lab_partner_id' => filled($this->validated('lab_partner_id')) ? (int) $this->validated('lab_partner_id') : null,
        ];
    }
}
