<?php

namespace App\Compliance;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * A range of calendar days in the clinic's timezone, inclusive, for the
 * analytics reports. Defaults to the last 30 days.
 */
final readonly class ReportPeriod
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    private function __construct(public string $from, public string $to) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Reject periods longer than MAX_DAYS, once the dates themselves are valid.
     */
    public static function checkLength(Validator $validator): void
    {
        $data = $validator->getData();

        if ($validator->errors()->isNotEmpty() || ! filled($data['from'] ?? null) || ! filled($data['to'] ?? null)) {
            return;
        }

        if (self::of((string) $data['from'], (string) $data['to'])->days() > self::MAX_DAYS) {
            $validator->errors()->add('to', 'Choose a period of at most '.self::MAX_DAYS.' days.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        if (filled($validated['from'] ?? null)) {
            return self::of((string) $validated['from'], (string) ($validated['to'] ?? self::today()));
        }

        $today = Carbon::parse(self::today());

        return self::of($today->copy()->subDays(self::DEFAULT_DAYS - 1)->toDateString(), $today->toDateString());
    }

    public static function of(string $from, string $to): self
    {
        return new self($from, $to);
    }

    public static function today(): string
    {
        return now((string) config('clinic.timezone'))->toDateString();
    }

    public function days(): int
    {
        return (int) Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to)) + 1;
    }

    /**
     * The first moment of the period, in UTC, as stored.
     */
    public function startsAt(): string
    {
        return Carbon::createFromFormat('!Y-m-d', $this->from, (string) config('clinic.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The first moment after the period, in UTC, as stored.
     */
    public function endsBefore(): string
    {
        return Carbon::createFromFormat('!Y-m-d', $this->to, (string) config('clinic.timezone'))->addDay()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @return array{from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'days' => $this->days()];
    }
}
