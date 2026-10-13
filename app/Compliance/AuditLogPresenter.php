<?php

namespace App\Compliance;

use App\Models\AuditLog;

/**
 * One audit entry as shown on screen or written to an export. Exports also
 * carry the hash chain, so a copy can be checked against the live log.
 */
final class AuditLogPresenter
{
    /**
     * Export columns, in order.
     *
     * @var array<int, string>
     */
    public const EXPORT_COLUMNS = [
        'id', 'created_at', 'event', 'category', 'user_id', 'actor_email', 'auditable_type', 'auditable_id',
        'ip_address', 'user_agent', 'old_values', 'new_values', 'metadata', 'previous_hash', 'hash',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(AuditLog $log, bool $withHashes = false): array
    {
        $row = [
            'id' => $log->id,
            'created_at' => $log->created_at->toIso8601String(),
            'event' => $log->event->value,
            'category' => $log->event->category()->value,
            'user_id' => $log->user_id,
            'actor_email' => $log->actor_email,
            'auditable_type' => $log->auditable_type,
            'auditable_id' => $log->auditable_id,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
        ];

        if ($withHashes) {
            $row['previous_hash'] = $log->previous_hash;
            $row['hash'] = $log->hash;
        }

        return $row;
    }

    /**
     * A CSV line's cells. Structured values are JSON, and text that a
     * spreadsheet would run as a formula is prefixed with a quote.
     *
     * @return array<int, string>
     */
    public static function csvCells(AuditLog $log): array
    {
        $row = self::row($log, withHashes: true);

        return array_map(function (string $column) use ($row): string {
            $value = $row[$column];
            $cell = is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value;

            return preg_match('/^[=+\-@\t\r]/', $cell) === 1 ? "'".$cell : $cell;
        }, self::EXPORT_COLUMNS);
    }
}
