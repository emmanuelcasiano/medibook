<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\GetAuditFilterOptions;
use App\Actions\Admin\ListAuditLogs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListAuditLogsRequest;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    /** The audit log, read only. There is no other method on purpose: nothing can change a row from here. */
    public function index(ListAuditLogsRequest $request, ListAuditLogs $list, GetAuditFilterOptions $options): Response
    {
        Gate::authorize('viewAny', AuditLog::class);

        $logs = $list->handle(
            $request->actorId(),
            $request->action(),
            $request->subjectType(),
            $request->subjectId(),
            $request->day(),
        )
            // Keep the filters in the "next" and "previous" links.
            ->withQueryString()
            ->through(fn (AuditLog $log): array => (new AuditLogResource($log))->resolve());

        return Inertia::render('admin/audit-logs/index', [
            'logs' => $logs,
            'filters' => $request->filters(),
            'options' => $options->handle(),
        ]);
    }
}
