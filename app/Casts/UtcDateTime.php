<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Stores a date-time in UTC whatever timezone it was given in.
 *
 * Eloquent's built-in datetime cast formats the value as-is, so a time given
 * in the clinic's timezone would be stored as if it were UTC.
 *
 * @implements CastsAttributes<Carbon, mixed>
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);

        return $date->utc()->format('Y-m-d H:i:s');
    }
}
