<?php

namespace App\Models;

use App\Enums\BiomarkerCategory;
use Database\Factories\BiomarkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A measurable marker in the catalog, with the units results may use.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property BiomarkerCategory $category
 * @property array<int, string> $units
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'category', 'units', 'description', 'sort_order', 'is_active'])]
class Biomarker extends Model
{
    /** @use HasFactory<BiomarkerFactory> */
    use HasFactory;

    /**
     * @return HasMany<LabResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }

    public function allowsUnit(string $unit): bool
    {
        return in_array($unit, $this->units, true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BiomarkerCategory::class,
            'units' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
