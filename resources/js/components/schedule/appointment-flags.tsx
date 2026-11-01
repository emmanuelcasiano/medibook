import { ClipboardX, Siren } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ScheduleAppointment } from '@/types';

type Props = {
    appointment: ScheduleAppointment;
    /**
     * `icon`: small icons with a screen reader text, for a crowded grid block or list row.
     * `text`: the icon with its words written out, for the side panel.
     */
    variant?: 'icon' | 'text';
    className?: string;
};

export const emergencyText = 'Emergency flag';
export const intakeMissingText = 'Intake not filled in yet';

/**
 * The two flags staff watch for: the emergency flag (red) and "intake not filled in yet" (amber). The server sends
 * them to staff only, so for an admin both are missing and nothing is drawn. The meaning is always in words (a
 * screen reader text, a title, or visible text), never in the color alone.
 */
export default function AppointmentFlags({
    appointment,
    variant = 'icon',
    className,
}: Props) {
    const flags = [
        appointment.emergencyFlag && {
            key: 'emergency',
            text: emergencyText,
            Icon: Siren,
            tone: 'text-red-700 dark:text-red-400',
        },
        appointment.intakeMissing && {
            key: 'intake',
            text: intakeMissingText,
            Icon: ClipboardX,
            tone: 'text-amber-700 dark:text-amber-400',
        },
    ].filter((flag) => flag !== false && flag !== undefined);

    if (flags.length === 0) {
        return null;
    }

    if (variant === 'text') {
        return (
            <ul className={cn('space-y-1.5 text-sm font-medium', className)}>
                {flags.map(({ key, text, Icon, tone }) => (
                    <li
                        key={key}
                        className={cn('flex items-center gap-2', tone)}
                    >
                        <Icon aria-hidden className="size-4 shrink-0" />
                        {text}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <span className={cn('inline-flex items-center gap-1', className)}>
            {flags.map(({ key, text, Icon, tone }) => (
                <span key={key} title={text} className={tone}>
                    <Icon aria-hidden className="size-3.5" />
                    <span className="sr-only">{text}.</span>
                </span>
            ))}
        </span>
    );
}
