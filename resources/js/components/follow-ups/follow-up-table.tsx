import { Link } from '@inertiajs/react';
import { PhoneCall } from 'lucide-react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import EmptyState from '@/components/empty-state';
import FollowUpStateBadge from '@/components/follow-ups/follow-up-state-badge';
import { formatClinicShortMonth, clinicDayOfMonth } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { FollowUpState, StaffFollowUpRow } from '@/types';

type Props = {
    followUps: StaffFollowUpRow[];
    filter: FollowUpState | null;
    selectedUlid: string | null;
};

/** "2 Nov". The days are clinic dates, so the helpers read them in UTC and the browser's zone can't move one. */
function shortDay(date: string): string {
    return `${clinicDayOfMonth(date)} ${formatClinicShortMonth(date)}`;
}

/**
 * The follow-ups as a table. The patient's name is the link that opens the side panel (a real link, so the keyboard
 * works and the address can be shared), and the chosen row says so in text.
 */
export default function FollowUpTable({
    followUps,
    filter,
    selectedUlid,
}: Props) {
    if (followUps.length === 0) {
        return (
            <EmptyState icon={PhoneCall}>
                {filter === null
                    ? 'No follow-ups yet.'
                    : 'No follow-ups in this group.'}
            </EmptyState>
        );
    }

    return (
        <div className="overflow-x-auto rounded-xl border bg-card">
            <table className="w-full min-w-[40rem] text-sm">
                <caption className="sr-only">Follow-ups</caption>
                <thead>
                    <tr className="border-b text-left text-xs tracking-wider text-muted-foreground uppercase">
                        <th scope="col" className="px-4 py-3 font-semibold">
                            Patient
                        </th>
                        <th scope="col" className="px-4 py-3 font-semibold">
                            Asked by
                        </th>
                        <th scope="col" className="px-4 py-3 font-semibold">
                            Come back
                        </th>
                        <th scope="col" className="px-4 py-3 font-semibold">
                            Status
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {followUps.map((followUp) => {
                        const selected = followUp.ulid === selectedUlid;

                        return (
                            <tr
                                key={followUp.ulid}
                                data-follow-up={followUp.ulid}
                                className={cn(selected && 'bg-teal-soft')}
                            >
                                <td className="px-4 py-3">
                                    <Link
                                        href={FollowUpController.index({
                                            query:
                                                filter === null
                                                    ? {
                                                          selected:
                                                              followUp.ulid,
                                                      }
                                                    : {
                                                          filter,
                                                          selected:
                                                              followUp.ulid,
                                                      },
                                        })}
                                        preserveScroll
                                        preserveState
                                        aria-current={
                                            selected ? 'true' : undefined
                                        }
                                        className="rounded-sm font-semibold outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring"
                                    >
                                        {followUp.patientName}
                                        <span className="sr-only">
                                            , open the follow-up
                                        </span>
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {followUp.serviceName}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    {followUp.doctorName}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap">
                                    {shortDay(followUp.windowStartsOn)} to{' '}
                                    {shortDay(followUp.windowEndsOn)}
                                </td>
                                <td className="px-4 py-3">
                                    <FollowUpStateBadge
                                        state={followUp.state}
                                        label={followUp.stateLabel}
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
