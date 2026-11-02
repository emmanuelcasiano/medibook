import AppLogoIcon from '@/components/app-logo-icon';
import { cn } from '@/lib/utils';

/** The MediBook logo: a teal rounded square with a white plus. Pass a `size-*` class to resize it. */
export default function AppLogoMark({ className }: { className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex aspect-square size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground',
                className,
            )}
        >
            <AppLogoIcon className="size-3/4" />
        </span>
    );
}
