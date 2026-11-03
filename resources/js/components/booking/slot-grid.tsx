import { Check } from 'lucide-react';
import { formatClinicTime } from '@/lib/clinic-time';
import { cn } from '@/lib/utils';
import type { BookingSlot } from '@/types';

type Props = {
    slots: BookingSlot[];
    selected: string | null;
    onSelect: (startsAt: string) => void;
    /** For a narrow place such as the staff side panel, which sets its own number of columns. */
    className?: string;
};

/**
 * The free start times of one day. Each time is a real button, so the keyboard works without extra code. The times
 * are in a mono font so the columns line up, and the picked one is solid teal with a check, not only a color.
 */
export default function SlotGrid({
    slots,
    selected,
    onSelect,
    className,
}: Props) {
    return (
        <ul className={cn('grid grid-cols-3 gap-2 sm:grid-cols-4', className)}>
            {slots.map((slot) => {
                const isSelected = slot.startsAt === selected;

                return (
                    <li key={slot.startsAt}>
                        <button
                            type="button"
                            aria-pressed={isSelected}
                            onClick={() => onSelect(slot.startsAt)}
                            className={cn(
                                'relative min-h-11 w-full rounded-lg border border-input bg-card px-2 font-mono text-sm outline-none hover:border-primary focus-visible:ring-[3px] focus-visible:ring-ring',
                                isSelected &&
                                    'border-primary bg-primary font-semibold text-primary-foreground hover:bg-primary/90',
                            )}
                        >
                            {formatClinicTime(slot.startsAt)}
                            {isSelected && (
                                <Check
                                    aria-hidden
                                    className="absolute top-1 right-1 size-3"
                                />
                            )}
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
