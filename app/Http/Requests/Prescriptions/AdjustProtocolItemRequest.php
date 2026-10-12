<?php

namespace App\Http\Requests\Prescriptions;

use App\Models\ProtocolItem;
use App\Prescriptions\ProtocolItemRules;
use BackedEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Changes to one protocol item, with the reason. Fields left out are carried
 * over from the current version.
 */
class AdjustProtocolItemRequest extends FormRequest
{
    /**
     * Checked here, before validation, so a prescription the doctor may not
     * change is refused rather than reported as invalid.
     */
    public function authorize(): bool
    {
        $item = $this->route('protocolItem');

        return $item instanceof ProtocolItem && (bool) $this->user()?->can('managePrescriptions', $item->prescription);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...ProtocolItemRules::row(required: false),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give the reason for the adjustment.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $item = $this->route('protocolItem');

            if ($validator->errors()->isNotEmpty() || ! $item instanceof ProtocolItem) {
                return;
            }

            if ($item->isDiscontinued()) {
                $validator->errors()->add('reason', 'This item is no longer current. Adjust the latest version.');

                return;
            }

            $unchanged = collect($this->changes())->every(function (?string $value, string $field) use ($item) {
                $current = $item->{$field};

                return $value === ($current instanceof BackedEnum ? $current->value : $current);
            });

            if ($unchanged) {
                $validator->errors()->add('reason', 'Change at least one detail of the item.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return ProtocolItemRules::attributes($this->safe()->except('reason'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
