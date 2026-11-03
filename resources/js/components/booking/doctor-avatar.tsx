import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

/** A doctor's initials in a soft teal circle. The title ("Dr.") is left out, so "Dr. Ana Lim" is "AL", not "DL". */
export default function DoctorAvatar({
    name,
    className,
}: {
    name: string;
    className?: string;
}) {
    const getInitials = useInitials();

    return (
        <span
            aria-hidden
            className={cn(
                'flex size-14 shrink-0 items-center justify-center rounded-full bg-teal-soft text-lg font-semibold text-teal-ink',
                className,
            )}
        >
            {getInitials(name.replace(/^dr\.?\s+/i, ''))}
        </span>
    );
}
