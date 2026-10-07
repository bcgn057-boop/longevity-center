<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The single place where audit entries are written.
 *
 * Every entry is sealed with an HMAC-SHA256 over its contents and the previous
 * entry's hash, forming a chain that `php artisan audit:verify` can check.
 */
class AuditLogger
{
    /**
     * The previous_hash of the very first entry in the chain.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Value stored in place of redacted fields.
     */
    public const REDACTED = '[REDACTED]';

    /**
     * Keys whose values must never be written to the audit log.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'recovery_code',
        'code',
        'remember_token',
        'token',
        'secret',
        'credential',
        'public_key',
        '_token',
    ];

    /**
     * How many times to retry when another request appended to the chain first.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Record an audit entry.
     *
     * The actor defaults to the authenticated user. Pass $actorEmail when there is no
     * authenticated user but an identity was claimed (for example, a failed login).
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AuditEvent $event,
        ?User $actor = null,
        ?string $actorEmail = null,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): AuditLog {
        $actor ??= Auth::user();

        // Resolved per call (not injected) so long-lived listeners never see a stale request.
        $request = request();

        for ($attempt = 1; ; $attempt++) {
            $log = new AuditLog;

            $log->forceFill([
                'event' => $event,
                'user_id' => $actor?->getKey(),
                'actor_email' => $actorEmail ?? $actor?->email,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'old_values' => $this->redact($oldValues) ?: null,
                'new_values' => $this->redact($newValues) ?: null,
                'metadata' => $this->redact($metadata) ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 252) ?: null,
                'created_at' => now(),
                'previous_hash' => AuditLog::query()->latest('id')->value('hash') ?? self::GENESIS_HASH,
            ]);

            $log->hash = $this->hashFor($log);

            try {
                $log->save();

                return $log;
            } catch (UniqueConstraintViolationException $e) {
                // Another entry claimed the same previous_hash first; rebuild on the new tail.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Compute the chained HMAC for an entry from its stored contents.
     */
    public function hashFor(AuditLog $log): string
    {
        $payload = $this->canonicalize([
            'event' => $log->event->value,
            'user_id' => $log->user_id,
            'actor_email' => $log->actor_email,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id === null ? null : (string) $log->auditable_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at->format('Y-m-d H:i:s'),
        ]);

        return hash_hmac(
            'sha256',
            $log->previous_hash.'|'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (string) config('app.key'),
        );
    }

    /**
     * Replace sensitive values, at any depth, with a redaction marker.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && in_array(Str::lower($key), self::SENSITIVE_KEYS, true)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * Sort associative keys recursively so hashing does not depend on key order.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalize($value);
            }
        }

        return $values;
    }
}
