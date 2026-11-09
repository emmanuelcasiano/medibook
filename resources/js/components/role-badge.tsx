import { cn } from '@/lib/utils';
import type { Role } from '@/types';

export const roleLabels: Record<Role, string> = {
    patient: 'Patient',
    staff: 'Staff',
    doctor: 'Doctor',
    nurse: 'Nurse',
    admin: 'Admin',
};

// Colors come from `app.css`. The label is always shown, so the role never depends on color alone.
const tones: Record<Role, string> = {
    patient: 'bg-teal-soft text-teal-ink',
    staff: 'bg-info-soft text-info-ink',
    doctor: 'bg-violet-soft text-violet-ink',
    nurse: 'bg-amber-soft text-amber-ink',
    admin: 'bg-foreground text-background',
};

type Props = {
    role: Role;
    className?: string;
};

/** A small label with the role of the signed-in user, so each role can tell which part of the app it is in. */
export default function RoleBadge({ role, className }: Props) {
    return (
        <span
            data-slot="role-badge"
            className={cn(
                'inline-flex w-fit shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
                tones[role],
                className,
            )}
        >
            {roleLabels[role]}
        </span>
    );
}
