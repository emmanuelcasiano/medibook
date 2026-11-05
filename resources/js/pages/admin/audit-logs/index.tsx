import { Head, Link, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import AuditLogController from '@/actions/App/Http/Controllers/Admin/AuditLogController';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { formatClinicDateTime } from '@/lib/clinic-time';
import type {
    AdminAuditLogFilters,
    AdminAuditLogListItem,
    AdminAuditLogOptions,
    Paginated,
} from '@/types';

type Props = {
    logs: Paginated<AdminAuditLogListItem>;
    filters: AdminAuditLogFilters;
    options: AdminAuditLogOptions;
};

/**
 * The filters live in the address (a GET), so a filtered view can be bookmarked or shared. Two details:
 * - `preserveState` keeps this form mounted across the visit, so a validation error from the server can show next to
 *   the field. A GET visit drops the page state by default, which would remount the form first.
 * - Empty filters are left out of the address. `useForm` applies `transform` before the query string is built.
 *   (The `<Form>` component builds the query string first, so its `transform` can't do this.)
 *
 * The page keys this component on the applied filters, so "Clear filters" resets the fields.
 */
function AuditFilterForm({
    filters,
    options,
}: {
    filters: AdminAuditLogFilters;
    options: AdminAuditLogOptions;
}) {
    const form = useForm<AdminAuditLogFilters>(filters);
    const hasFilters = Object.values(filters).some((value) => value !== '');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        form.transform((data) =>
            Object.fromEntries(
                Object.entries(data).filter(([, value]) => value !== ''),
            ),
        );
        form.get(AuditLogController.index.url(), { preserveState: true });
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div className="grid gap-2">
                <Label htmlFor="actor">Actor</Label>
                <NativeSelect
                    id="actor"
                    value={form.data.actor}
                    onChange={(e) => form.setData('actor', e.target.value)}
                    aria-invalid={form.errors.actor ? true : undefined}
                >
                    <option value="">Anyone</option>
                    {options.actors.map((actor) => (
                        <option key={actor.id} value={actor.id}>
                            {actor.name}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.actor} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="action">Action</Label>
                <NativeSelect
                    id="action"
                    value={form.data.action}
                    onChange={(e) => form.setData('action', e.target.value)}
                    aria-invalid={form.errors.action ? true : undefined}
                >
                    <option value="">Any action</option>
                    {options.actions.map((action) => (
                        <option key={action} value={action}>
                            {action}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.action} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="date">Date</Label>
                <Input
                    id="date"
                    type="date"
                    value={form.data.date}
                    onChange={(e) => form.setData('date', e.target.value)}
                    aria-invalid={form.errors.date ? true : undefined}
                />
                <InputError message={form.errors.date} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="subject_type">Subject</Label>
                <NativeSelect
                    id="subject_type"
                    value={form.data.subject_type}
                    onChange={(e) =>
                        form.setData('subject_type', e.target.value)
                    }
                    aria-invalid={form.errors.subject_type ? true : undefined}
                >
                    <option value="">Any subject</option>
                    {options.subjectTypes.map((type) => (
                        <option key={type} value={type}>
                            {type}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.subject_type} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="subject_id">Subject id</Label>
                <Input
                    id="subject_id"
                    type="number"
                    min={1}
                    inputMode="numeric"
                    value={form.data.subject_id}
                    onChange={(e) => form.setData('subject_id', e.target.value)}
                    aria-invalid={form.errors.subject_id ? true : undefined}
                />
                <InputError message={form.errors.subject_id} />
            </div>

            <div className="flex items-end gap-3">
                <Button type="submit" disabled={form.processing}>
                    Filter
                </Button>
                {hasFilters && (
                    <Button asChild variant="ghost">
                        <Link href={AuditLogController.index()}>
                            Clear filters
                        </Link>
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function AdminAuditLogsIndex({ logs, filters, options }: Props) {
    return (
        <>
            <Head title="Audit log" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Audit log"
                    description={
                        <p className="flex items-center gap-1.5">
                            <Lock className="size-3.5 shrink-0" aria-hidden />
                            Append-only. Entries can&apos;t be edited or
                            deleted. No health text is stored here.
                        </p>
                    }
                />

                <AuditFilterForm
                    key={JSON.stringify(filters)}
                    filters={filters}
                    options={options}
                />

                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full text-sm">
                        <caption className="sr-only">
                            Audit log entries, newest first
                        </caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Time
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Actor
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Action
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Subject
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Details
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {logs.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No events match these filters.
                                    </td>
                                </tr>
                            )}
                            {logs.data.map((log) => (
                                <tr key={log.id} className="align-top">
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {formatClinicDateTime(log.createdAt)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {log.actorName === null ? (
                                            <span className="text-muted-foreground">
                                                System or guest
                                            </span>
                                        ) : (
                                            <>
                                                <div className="font-medium">
                                                    {log.actorName}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {log.actorRoleLabel}
                                                </div>
                                            </>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {log.action}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {log.subject ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {log.details.length === 0
                                            ? '-'
                                            : log.details.map((line) => (
                                                  <div key={line}>{line}</div>
                                              ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <p className="text-muted-foreground">
                        {logs.from !== null
                            ? `Showing ${logs.from} to ${logs.to} of ${logs.total}`
                            : 'No entries'}
                    </p>
                    <div className="flex gap-2">
                        {logs.prev_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={logs.prev_page_url} preserveScroll>
                                    Previous
                                </Link>
                            </Button>
                        ) : null}
                        {logs.next_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={logs.next_page_url} preserveScroll>
                                    Next
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </nav>
            </div>
        </>
    );
}

AdminAuditLogsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Audit log',
            href: AuditLogController.index(),
        },
    ],
};
