import type { ScheduleView } from '@/hooks/use-schedule-view';
import { cn } from '@/lib/utils';

type Props = {
    view: ScheduleView;
    options: Array<{ view: ScheduleView; label: string }>;
    onChange: (view: ScheduleView) => void;
};

/**
 * The segmented control that switches the schedule between its views (Grid | List on a wide screen, List | By doctor
 * on a phone). Real buttons with `aria-pressed`, so the keyboard and screen readers get the same choice as the mouse.
 */
export default function ViewToggle({ view, options, onChange }: Props) {
    return (
        <div
            role="group"
            aria-label="Schedule view"
            className="inline-flex rounded-md border bg-card p-0.5"
        >
            {options.map((option) => {
                const pressed = option.view === view;

                return (
                    <button
                        key={option.view}
                        type="button"
                        aria-pressed={pressed}
                        onClick={() => onChange(option.view)}
                        className={cn(
                            'h-8 rounded-sm px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            pressed
                                ? 'bg-primary text-primary-foreground'
                                : 'hover:bg-accent',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
