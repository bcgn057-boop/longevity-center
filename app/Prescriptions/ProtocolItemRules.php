<?php

namespace App\Prescriptions;

use App\Enums\ProtocolFrequency;
use App\Enums\ProtocolItemType;
use Illuminate\Validation\Rule;

/**
 * Validation shared by every form that writes a protocol item.
 */
final class ProtocolItemRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function row(string $prefix = '', bool $required = true): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            "{$prefix}type" => [$presence, Rule::enum(ProtocolItemType::class)],
            "{$prefix}name" => [$presence, 'string', 'max:120'],
            "{$prefix}dosage" => ['nullable', 'string', 'max:120'],
            "{$prefix}instructions" => ['nullable', 'string', 'max:500'],
            "{$prefix}frequency" => [$presence, Rule::enum(ProtocolFrequency::class)],
        ];
    }

    /**
     * Model attributes from a validated row, trimmed, with blanks as null.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function attributes(array $row): array
    {
        $attributes = [];

        foreach (['type', 'name', 'dosage', 'instructions', 'frequency'] as $field) {
            if (array_key_exists($field, $row)) {
                $attributes[$field] = filled($row[$field]) ? trim((string) $row[$field]) : null;
            }
        }

        return $attributes;
    }
}
