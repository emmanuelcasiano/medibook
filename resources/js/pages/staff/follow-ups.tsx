import { Head, Link, router, usePage } from '@inertiajs/react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import FollowUpPanel from '@/components/follow-ups/follow-up-panel';
import FollowUpTable from '@/components/follow-ups/follow-up-table';
import FollowUpTiles from '@/components/follow-ups/follow-up-tiles';
import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { formatClinicDate } from '@/lib/clinic-time';
import type {
    FollowUpBookingOptions,
    FollowUpCounts,
    FollowUpState,
    StaffFollowUpPanel,
    StaffFollowUpRow,
} from '@/types';

type Props = {
    followUps: StaffFollowUpRow[];
    counts: FollowUpCounts;
    /** The state tile that filters the list, or null for the whole list. */
    filter: FollowUpState | null;
    /** Today, as a clinic date ('Y-m-d'). */
    today: string;
    /** The follow-up whose side panel is open, or null. */
    selected: StaffFollowUpPanel | null;
    /**
     * The Book picker's days and times. Not part of the first load: the panel's Book section asks for it, so it is
     * `undefined` until then, and `null` when the user may not book this follow-up.
     */
    book?: FollowUpBookingOptions | null;
};

export default function StaffFollowUps({
    followUps,
    counts,
    filter,
    today,
    selected,
    book,
}: Props) {
    const { auth } = usePage().props;
    const viewOnly = auth.user?.role === 'admin';

    // The panel closes at once, and the visit then refreshes the list behind it. If the visit fails, the props come
    // back and the panel with them. `async: false` makes it cancel an "open" still waiting for its answer.
    function closePanel() {
        router
            .optimistic(() => ({ selected: null }))
            .get(
                FollowUpController.index.url({
                    query: filter === null ? {} : { filter },
                }),
                {},
                { preserveScroll: true, preserveState: true, async: false },
            );
    }

    return (
        <>
            <Head title="Follow-ups" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={
                        <>
                            Follow-ups
                            {viewOnly && (
                                <Badge variant="outline">View only</Badge>
                            )}
                        </>
                    }
                    description={
                        <p>
                            Patients a doctor asked to come back. Today is{' '}
                            {formatClinicDate(today)}.
                        </p>
                    }
                    actions={
                        filter !== null && (
                            <Button asChild variant="secondary" size="sm">
                                <Link
                                    href={FollowUpController.index()}
                                    preserveScroll
                                    preserveState
                                >
                                    Show all
                                </Link>
                            </Button>
                        )
                    }
                />

                <FollowUpTiles counts={counts} filter={filter} />

                <FollowUpTable
                    followUps={followUps}
                    filter={filter}
                    selectedUlid={selected?.ulid ?? null}
                />
            </div>

            {/* The panel is a slide-over on every screen width: the sheet traps the focus, closes on Esc and a click outside. */}
            {selected && (
                <Sheet
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            closePanel();
                        }
                    }}
                >
                    <SheetContent
                        // The panel's own heading takes the focus, so the sheet must not pick a button.
                        onOpenAutoFocus={(event) => event.preventDefault()}
                        aria-describedby={undefined}
                        className="w-full gap-0 overflow-y-auto data-[state=open]:duration-200 sm:max-w-sm"
                    >
                        <FollowUpPanel
                            key={selected.ulid}
                            followUp={selected}
                            book={book}
                        />
                    </SheetContent>
                </Sheet>
            )}
        </>
    );
}

StaffFollowUps.layout = {
    breadcrumbs: [
        {
            title: 'Follow-ups',
            href: FollowUpController.index(),
        },
    ],
};
