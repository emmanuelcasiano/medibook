<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\AuditLog;
use App\Models\User;

final class GetAuditFilterOptions
{
    /**
     * The values the audit screen's filter lists offer: only what is in the log, so every choice finds at least one
     * row. Three small queries, none per row.
     *
     * @return array{
     *     actors: list<array{id: int, name: string}>,
     *     actions: list<string>,
     *     subjectTypes: list<string>
     * }
     */
    public function handle(): array
    {
        $actors = User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('actor_id')->select('actor_id')->distinct())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]);

        $actions = AuditLog::query()
            ->distinct()
            ->orderBy('action')
            ->get(['action'])
            ->map(fn (AuditLog $log): string => $log->action);

        // The log stores full class names. The page only needs the short name, which `ListAuditLogs` turns back.
        $subjectTypes = AuditLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->get(['subject_type'])
            ->map(fn (AuditLog $log): string => class_basename((string) $log->subject_type))
            ->unique()
            ->sort();

        return [
            'actors' => array_values($actors->all()),
            'actions' => array_values($actions->all()),
            'subjectTypes' => array_values($subjectTypes->all()),
        ];
    }
}
