<?php

namespace App\Http\Requests\Prescriptions;

use App\Prescriptions\ProtocolItemRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new prescription with its first items. Dates are calendar days in the
 * clinic's timezone.
 */
class StorePrescriptionRequest extends FormRequest
{
    public const MAX_ITEMS = 30;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            ...ProtocolItemRules::row('items.*.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.min' => 'Add at least one item.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prescription(): array
    {
        return [
            'title' => trim((string) $this->validated('title')),
            'notes' => filled($this->validated('notes')) ? trim((string) $this->validated('notes')) : null,
            'starts_on' => $this->validated('starts_on'),
            'ends_on' => $this->validated('ends_on'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return array_map(
            fn (array $row, int $index) => [...ProtocolItemRules::attributes($row), 'sort_order' => $index],
            $items = array_values((array) $this->validated('items')),
            array_keys($items),
        );
    }
}
