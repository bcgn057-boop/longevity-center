<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property UserRole $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'name'])]
class Role extends Model
{
    /**
     * Get the role record for the given role, creating it if it does not exist yet.
     */
    public static function fromEnum(UserRole $role): self
    {
        return static::firstOrCreate(
            ['slug' => $role->value],
            ['name' => $role->label()],
        );
    }

    /**
     * Get the users that have this role.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => UserRole::class,
        ];
    }
}
