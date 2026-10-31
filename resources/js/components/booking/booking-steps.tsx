import { cn } from '@/lib/utils';

const steps = ['Service', 'Doctor', 'Time', 'Confirm'];

/** The steps of booking. Each one has a text label and a number, so the state never depends on color alone. */
export default function BookingSteps({ current }: { current: 1 | 2 | 3 | 4 }) {
    return (
        <nav aria-label="Booking steps">
            <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                {steps.map((label, index) => {
                    const number = index + 1;
                    const isCurrent = number === current;
                    const isDone = number < current;

                    return (
                        <li
                            key={label}
                            aria-current={isCurrent ? 'step' : undefined}
                            className="flex items-center gap-2"
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'flex size-6 items-center justify-center rounded-full border text-xs font-medium',
                                    isCurrent &&
                                        'border-primary bg-primary text-primary-foreground',
                                    isDone && 'bg-muted',
                                )}
                            >
                                {number}
                            </span>
                            <span
                                className={cn(
                                    isCurrent
                                        ? 'font-medium'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {label}
                            </span>
                            {isCurrent && (
                                <span className="sr-only">(current step)</span>
                            )}
                            {isDone && <span className="sr-only">(done)</span>}
                            {number < steps.length && (
                                <span
                                    aria-hidden
                                    className="mx-1 h-px w-4 bg-border"
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
