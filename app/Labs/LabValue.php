<?php

namespace App\Labs;

use App\Enums\ResultFlag;
use InvalidArgumentException;

/**
 * A lab value as reported: a number, optionally with a comparator such as
 * "<0.5" when the result is below what the lab can measure.
 */
final readonly class LabValue
{
    private const PATTERN = '/^(<=|>=|<|>)?(-?\d{1,10}(?:\.\d{1,6})?)$/';

    private function __construct(
        public ?string $comparator,
        public string $number,
    ) {}

    /**
     * @throws InvalidArgumentException when the value is not a number (with optional comparator)
     */
    public static function parse(string $value): self
    {
        $value = str_replace(' ', '', trim($value));

        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException('A lab value must be a number, optionally starting with <, <=, > or >=.');
        }

        return new self($matches[1] === '' ? null : $matches[1], $matches[2]);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, str_replace(' ', '', trim($value))) === 1;
    }

    public function __toString(): string
    {
        return ($this->comparator ?? '').$this->number;
    }

    /**
     * Compare against the lab's printed range. Values reported with a
     * comparator ("<0.5") are not flagged: their exact position is unknown.
     */
    public function flag(?string $low, ?string $high): ?ResultFlag
    {
        if ($this->comparator !== null || ($low === null && $high === null)) {
            return null;
        }

        $value = (float) $this->number;

        return match (true) {
            $low !== null && $value < (float) $low => ResultFlag::Low,
            $high !== null && $value > (float) $high => ResultFlag::High,
            default => ResultFlag::Normal,
        };
    }
}
