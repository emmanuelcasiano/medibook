<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListAuditLogs
{
    /** Every audited subject is a model in this namespace; the log stores the full class name. */
    public const string SUBJECT_NAMESPACE = 'App\\Models\\';

    public const int PER_PAGE = 50;

    /**
     * One page of the audit log, newest first. Every filter is optional and they combine with AND.
     *
     * - `$subjectType` is the short class name (`Appointment`), which the page shows. It is turned into the stored
     *   class name here and bound as a value, so it can't change the query.
     * - `$day` is a clinic date. It is turned into a UTC range, so an entry made at 07:30 Manila time (23:30 UTC the
     *   day before) shows under the clinic day it happened on. The range uses the `created_at` index.
     * - The actor is eager loaded in one query, so a page of 50 rows costs two queries, not 51.
     *
     * Only the audit columns are read. The log holds no health text (PROJECT.md F10), and a subject is never loaded.
     *
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function handle(
        ?int $actorId = null,
        ?string $action = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?CarbonImmutable $day = null,
    ): LengthAwarePaginator {
        $query = AuditLog::query()->with('actor:id,name,role');

        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }

        if ($action !== null) {
            $query->where('action', $action);
        }

        if ($subjectType !== null) {
            $query->where('subject_type', self::SUBJECT_NAMESPACE.$subjectType);
        }

        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        if ($day !== null) {
            $start = $day->setTimezone((string) config('app.clinic_timezone'))->startOfDay();

            // The column holds UTC, and the query builder writes a date as it is, so convert the bounds first.
            $query->where('created_at', '>=', $start->utc())
                ->where('created_at', '<', $start->addDay()->utc());
        }

        // `id` breaks ties between rows written in the same second, so a page never repeats or skips a row.
        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE);
    }
}
