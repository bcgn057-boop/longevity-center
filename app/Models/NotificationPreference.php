<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The current consent of one recipient for one channel. Written only by
 * NotificationConsent, which also audits every change.
 *
 * @property int $id
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property NotificationChannel $channel
 * @property string|null $destination_hash
 * @property Carbon|null $consented_at
 * @property Carbon|null $withdrawn_at
 * @property int|null $consent_version
 * @property string $source
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model
{
    /**
     * Consent was recorded by clinic staff on the patient's behalf.
     */
    public const SOURCE_STAFF = 'staff';

    /**
     * Consent was given or withdrawn by the patient in the portal.
     */
    public const SOURCE_SELF = 'self';

    /**
     * Get the recipient the preference belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who recorded the latest change.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Determine if consent is currently given.
     */
    public function isActive(): bool
    {
        return $this->consented_at !== null && $this->withdrawn_at === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'consented_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'consent_version' => 'integer',
        ];
    }
}
