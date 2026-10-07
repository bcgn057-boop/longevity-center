<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

class AuditLogController extends Controller
{
    /**
     * Session key marking that this session's view of the audit log was recorded.
     */
    private const VIEWED_SESSION_KEY = 'audit_log.view_recorded';

    /**
     * Show the audit log. Read-only: entries can never be edited or removed.
     */
    public function index(Request $request, AuditLogger $audit): Response
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', Rule::enum(AuditEvent::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->recordView($request, $audit, $filters);

        return Inertia::render('admin/AuditLogs', [
            'logs' => fn () => AuditLog::query()
                ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
                ->when($filters['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'event' => $log->event->value,
                    'user_id' => $log->user_id,
                    'actor_email' => $log->actor_email,
                    'auditable_type' => $log->auditable_type,
                    'auditable_id' => $log->auditable_id,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'metadata' => $log->metadata,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at->toIso8601String(),
                ]),
            'filters' => fn () => [
                'event' => $filters['event'] ?? null,
                'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
            ],
            'events' => fn () => array_map(fn (AuditEvent $event) => $event->value, AuditEvent::cases()),
        ]);
    }

    /**
     * Audit the view on full page loads. Partial Inertia reloads (filtering,
     * paging) are skipped, but only once this session's full load has been
     * recorded: the partial-reload header is client-controlled, so it must not
     * be usable to read the audit log without leaving a trace.
     *
     * @param  array<string, mixed>  $filters
     */
    private function recordView(Request $request, AuditLogger $audit, array $filters): void
    {
        $isPartialReload = $request->header(Header::PARTIAL_COMPONENT) === 'admin/AuditLogs';

        if ($isPartialReload && $request->session()->get(self::VIEWED_SESSION_KEY) === true) {
            return;
        }

        $audit->record(AuditEvent::AuditLogViewed, metadata: ['filters' => $filters]);

        $request->session()->put(self::VIEWED_SESSION_KEY, true);
    }
}
