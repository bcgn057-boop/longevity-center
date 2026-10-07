<?php

namespace App\Models;

use App\Enums\AuditEvent;
use App\Exceptions\AuditLogImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An append-only, hash-chained audit entry. Entries are written only by
 * App\Services\AuditLogger and can never be updated or deleted.
 *
 * @property int $id
 * @property AuditEvent $event
 * @property int|null $user_id
 * @property string|null $actor_email
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class AuditLog extends Model
{
    /**
     * Entries have a creation time only; they are never updated.
     */
    public $timestamps = false;

    /**
     * Get the user who performed the action, if they still exist.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the record the action was performed on.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Prevent updates and deletes at the application level.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw AuditLogImmutableException::update());
        static::deleting(fn () => throw AuditLogImmutableException::delete());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
