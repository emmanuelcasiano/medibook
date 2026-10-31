import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { BookingSlot } from '@/types';

type Props = {
    slots: BookingSlot[];
    selected: string | null;
    onSelect: (startsAt: string) => void;
};

/** The free start times of one day. Each time is a real button, so the keyboard works without extra code. */
export default function SlotGrid({ slots, selected, onSelect }: Props) {
    return (
        <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
            {slots.map((slot) => {
                const isSelected = slot.startsAt === selected;

                return (
                    <li key={slot.startsAt}>
                        <button
                            type="button"
                            aria-pressed={isSelected}
                            onClick={() => onSelect(slot.startsAt)}
                            className={cn(
                                'min-h-11 w-full rounded-md border px-2 text-sm font-medium outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                isSelected &&
                                    'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                            )}
                        >
                            {formatClinicTime(slot.startsAt)}
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
