import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const steps = ['Service', 'Doctor', 'Time', 'Confirm'];

/**
 * The steps of booking. Done is soft teal with a check, the current step is solid teal, and each one has a text label
 * and a number, so the state never depends on color alone. A phone gets one line ("Step 3 of 4 · Time") and a thin
 * progress bar, because four pills do not fit in 390 px.
 */
export default function BookingSteps({ current }: { current: 1 | 2 | 3 | 4 }) {
    return (
        <nav aria-label="Booking steps">
            <div className="space-y-2 sm:hidden">
                <p className="text-sm font-medium">
                    Step {current} of {steps.length} · {steps[current - 1]}
                </p>
                <div aria-hidden className="flex gap-1">
                    {steps.map((label, index) => (
                        <span
                            key={label}
                            className={cn(
                                'h-1.5 flex-1 rounded-full bg-border',
                                index + 1 < current && 'bg-teal-line',
                                index + 1 === current && 'bg-primary',
                            )}
                        />
                    ))}
                </div>
            </div>

            <ol className="hidden flex-wrap items-center gap-2 text-sm sm:flex">
                {steps.map((label, index) => {
                    const number = index + 1;
                    const isCurrent = number === current;
                    const isDone = number < current;

                    return (
                        <li
                            key={label}
                            aria-current={isCurrent ? 'step' : undefined}
                            className={cn(
                                'flex h-9 items-center gap-2 rounded-full border py-1 pr-4 pl-1.5 font-medium',
                                isCurrent &&
                                    'border-primary bg-primary text-primary-foreground',
                                isDone &&
                                    'border-teal-line bg-teal-soft text-teal-ink',
                                !isCurrent &&
                                    !isDone &&
                                    'bg-card text-muted-foreground',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-6 items-center justify-center rounded-full border text-xs',
                                    isCurrent && 'border-primary-foreground/40',
                                    isDone && 'border-teal-line bg-card',
                                )}
                            >
                                {isDone ? (
                                    <Check className="size-3.5" />
                                ) : (
                                    number
                                )}
                            </span>
                            {label}
                            {isCurrent && (
                                <span className="sr-only">(current step)</span>
                            )}
                            {isDone && <span className="sr-only">(done)</span>}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
