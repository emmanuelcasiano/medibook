import { cn } from '@/lib/utils';
import type { Role } from '@/types';

/** The mockup's role colors: patient teal, staff blue, doctor indigo, admin dark. A nurse is violet. */
const tones: Record<Role, string> = {
    patient: 'bg-teal-soft text-teal-ink',
    staff: 'bg-info-soft text-info-ink',
    doctor: 'bg-ai-soft text-ai-ink',
    nurse: 'bg-violet-soft text-violet-ink',
    admin: 'bg-foreground text-background',
};

type Props = {
    role: Role;
    /** The label the server sends. It is always shown, so the role never depends on color alone. */
    label: string;
};

export default function RoleBadge({ role, label }: Props) {
    return (
        <span
            data-slot="role-badge"
            className={cn(
                'inline-flex w-fit items-center rounded-md px-2 py-0.5 text-[11px] font-semibold tracking-wide whitespace-nowrap uppercase',
                tones[role],
            )}
        >
            {label}
        </span>
    );
}
