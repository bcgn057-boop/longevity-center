<?php

namespace App\Http\Controllers\Compliance;

use App\Compliance\AuditLogFilter;
use App\Compliance\AuditLogPresenter;
use App\Enums\AuditCategory;
use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log for compliance review, and its exports. Read-only: entries
 * can never be edited or removed. Every view and export is itself audited.
 * Authorized on the routes.
 */
class AuditLogController extends Controller
{
    /**
     * Session key marking that this session's view of the audit log was recorded.
     */
    private const VIEWED_SESSION_KEY = 'audit_log.view_recorded';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $filter = AuditLogFilter::from($request->validate([
            ...AuditLogFilter::rules(),
            'page' => ['nullable', 'integer', 'min:1'],
        ]));

        $this->recordView($request, $filter);

        return Inertia::render('compliance/AuditLogs', [
            'logs' => fn () => $filter->apply(AuditLog::query())
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AuditLog $log) => AuditLogPresenter::row($log)),
            'filters' => fn () => $filter->toArray(),
            'events' => fn () => array_map(fn (AuditEvent $event) => [
                'value' => $event->value,
                'category' => $event->category()->value,
            ], AuditEvent::cases()),
            'categories' => fn () => array_map(fn (AuditCategory $category) => [
                'value' => $category->value,
                'label' => $category->label(),
            ], AuditCategory::cases()),
            'staff' => fn () => $this->staff(),
            'can' => fn () => ['export' => (bool) $request->user()?->can('exportAuditLogs')],
        ]);
    }

    /**
     * Download the matching entries, oldest first, as CSV or JSON. The export
     * is recorded before it starts, and covers the log as it stood then.
     */
    public function export(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            ...AuditLogFilter::rules(),
            'format' => ['required', Rule::in(['csv', 'json'])],
        ]);
        $filter = AuditLogFilter::from($validated);
        $format = (string) $validated['format'];

        $upTo = (int) AuditLog::query()->max('id');
        $query = fn () => $filter->apply(AuditLog::query())->where('id', '<=', $upTo);
        $count = $query()->count();

        $this->audit->record(AuditEvent::AuditLogExported, metadata: [
            'filters' => $filter->toArray(),
            'format' => $format,
            'entries' => $count,
            'up_to_id' => $upTo,
        ]);

        $exportedAt = now()->utc();
        $filename = 'audit-log-'.$exportedAt->format('Ymd-His').'.'.$format;
        $exporter = $request->user();

        return response()->streamDownload(function () use ($format, $query, $filter, $count, $upTo, $exportedAt, $exporter) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            if ($format === 'csv') {
                fputcsv($out, AuditLogPresenter::EXPORT_COLUMNS, escape: '');

                foreach ($query()->lazyById(500) as $log) {
                    fputcsv($out, AuditLogPresenter::csvCells($log), escape: '');
                }

                fclose($out);

                return;
            }

            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

            fwrite($out, '{"exported_at":'.json_encode($exportedAt->toIso8601String(), $flags)
                .',"exported_by":'.json_encode($exporter?->email, $flags)
                .',"filters":'.json_encode((object) $filter->toArray(), $flags)
                .',"up_to_id":'.$upTo
                .',"count":'.$count
                .',"entries":[');

            $first = true;

            foreach ($query()->lazyById(500) as $log) {
                fwrite($out, ($first ? '' : ',').json_encode(AuditLogPresenter::row($log, withHashes: true), $flags));
                $first = false;
            }

            fwrite($out, ']}');
            fclose($out);
        }, $filename, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Staff accounts to filter by. Patients are filtered by patient record.
     *
     * @return array<int, array{id: int, name: string, email: string, role: string|null}>
     */
    private function staff(): array
    {
        return User::query()
            ->with('role')
            ->whereHas('role', fn ($query) => $query->whereIn('slug', array_map(fn (UserRole $role) => $role->value, UserRole::staff())))
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug->label(),
            ])
            ->all();
    }

    /**
     * Audit the view on full page loads. Partial Inertia reloads (filtering,
     * paging) are skipped, but only once this session's full load has been
     * recorded: the partial-reload header is client-controlled, so it must not
     * be usable to read the audit log without leaving a trace.
     */
    private function recordView(Request $request, AuditLogFilter $filter): void
    {
        $isPartialReload = $request->header(Header::PARTIAL_COMPONENT) === 'compliance/AuditLogs';

        if ($isPartialReload && $request->session()->get(self::VIEWED_SESSION_KEY) === true) {
            return;
        }

        $this->audit->record(AuditEvent::AuditLogViewed, metadata: ['filters' => $filter->toArray()]);

        $request->session()->put(self::VIEWED_SESSION_KEY, true);
    }
}
