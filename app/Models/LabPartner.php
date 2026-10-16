<?php

namespace App\Models;

use Database\Factories\LabPartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An external lab the clinic orders tests from.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property array<int, array{code: string, label: string}> $tests
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'tests', 'active'])]
class LabPartner extends Model
{
    /** @use HasFactory<LabPartnerFactory> */
    use HasFactory;

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @param  Builder<LabPartner>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The codes of the tests this partner offers.
     *
     * @return array<int, string>
     */
    public function testCodes(): array
    {
        return array_column($this->tests, 'code');
    }

    /**
     * Labels for the given test codes, in the partner's order.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function testLabels(array $codes): array
    {
        return array_values(array_map(
            fn (array $test) => $test['label'],
            array_filter($this->tests, fn (array $test) => in_array($test['code'], $codes, true)),
        ));
    }

    /**
     * @return array{id: int, name: string, tests: array<int, array{code: string, label: string}>}
     */
    public function toOption(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'tests' => $this->tests];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tests' => 'array',
            'active' => 'boolean',
        ];
    }
}
