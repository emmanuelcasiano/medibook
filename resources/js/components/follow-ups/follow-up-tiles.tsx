import { Link } from '@inertiajs/react';
import FollowUpController from '@/actions/App/Http/Controllers/Staff/FollowUpController';
import { cn } from '@/lib/utils';
import type { FollowUpCounts, FollowUpState } from '@/types';

type Props = {
    counts: FollowUpCounts;
    /** The tile that filters the list now, or null for the whole list. */
    filter: FollowUpState | null;
};

const tiles: Array<{
    state: Exclude<FollowUpState, 'dismissed'>;
    title: string;
    hint: string;
    tone: string;
}> = [
    {
        state: 'call',
        title: 'Needs a call',
        hint: 'Range ended or ends soon',
        tone: 'text-danger-ink',
    },
    {
        state: 'open',
        title: 'Open now',
        hint: 'Inside the range, not booked',
        tone: 'text-amber-ink',
    },
    {
        state: 'waiting',
        title: 'Coming up',
        hint: "Range hasn't started",
        tone: 'text-foreground',
    },
    {
        state: 'booked',
        title: 'Booked',
        hint: "From a doctor's request",
        tone: 'text-teal-ink',
    },
];

/**
 * The four counts that double as filters. A tile is a link, so the keyboard and the back button work, and the chosen
 * one says so in text (`aria-current`) and not only with a border. Choosing it again shows the whole list.
 */
export default function FollowUpTiles({ counts, filter }: Props) {
    return (
        <nav
            aria-label="Filter follow-ups by state"
            className="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            {tiles.map((tile) => {
                const chosen = filter === tile.state;

                return (
                    <Link
                        key={tile.state}
                        href={FollowUpController.index({
                            query: chosen ? {} : { filter: tile.state },
                        })}
                        preserveScroll
                        preserveState
                        aria-current={chosen ? 'true' : undefined}
                        className={cn(
                            'flex flex-col gap-1 rounded-xl border bg-card p-4 outline-none hover:border-primary focus-visible:ring-[3px] focus-visible:ring-ring',
                            chosen && 'border-2 border-primary p-[15px]',
                        )}
                    >
                        <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                            {tile.title}
                        </span>
                        <span
                            className={cn(
                                'text-3xl leading-none font-semibold',
                                tile.tone,
                            )}
                        >
                            {counts[tile.state]}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {tile.hint}
                        </span>
                    </Link>
                );
            })}
        </nav>
    );
}
