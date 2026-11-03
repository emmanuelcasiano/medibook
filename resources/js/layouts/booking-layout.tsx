import type { InertiaLinkProps } from '@inertiajs/react';
import { Link, usePage } from '@inertiajs/react';
import AppLogoMark from '@/components/app-logo-mark';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { dashboard, home, login, register } from '@/routes';
import { services } from '@/routes/booking';
import { home as myHome } from '@/routes/my';

/** A header link. The current section is teal and bold, and says so to screen readers with `aria-current`. */
function NavLink({
    href,
    children,
}: {
    href: NonNullable<InertiaLinkProps['href']>;
    children: React.ReactNode;
}) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const isActive = isCurrentOrParentUrl(href);

    return (
        <Link
            href={href}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
                'inline-flex h-11 items-center rounded-md px-3 text-sm font-medium transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                isActive && 'bg-teal-soft font-bold text-teal-ink',
            )}
        >
            {children}
        </Link>
    );
}

/**
 * The layout of the public booking pages. A guest can open them, so it can't use the app layout (that one
 * needs a signed-in user for its sidebar).
 */
export default function BookingLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth, name } = usePage().props;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:ring-2 focus:ring-ring"
            >
                Skip to the booking steps
            </a>

            <header className="border-b">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        {/* A signed-in user goes back to their own screen, a guest to the landing page. */}
                        <Link
                            href={
                                auth.user
                                    ? auth.user.role === 'patient'
                                        ? myHome()
                                        : dashboard()
                                    : home()
                            }
                            className="flex items-center gap-2 rounded-md font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <AppLogoMark className="size-8" />
                            <span className="text-lg tracking-tight">
                                {name}
                            </span>
                        </Link>

                        <nav
                            aria-label="Main"
                            className="flex items-center gap-1"
                        >
                            <NavLink href={services()}>Book</NavLink>
                            {auth.user?.role === 'patient' && (
                                <NavLink href={myHome()}>
                                    My appointments
                                </NavLink>
                            )}
                        </nav>
                    </div>

                    <nav
                        aria-label="Account"
                        className="flex items-center gap-2"
                    >
                        {auth.user ? (
                            // Patients already have "My appointments" above. Staff, doctors and admins are sent to
                            // their own screen by `/dashboard`.
                            auth.user.role !== 'patient' && (
                                <Button asChild variant="secondary">
                                    <Link href={dashboard()}>
                                        Go to my screen
                                    </Link>
                                </Button>
                            )
                        ) : (
                            <>
                                <Button asChild variant="secondary">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild>
                                    <Link href={register()}>Register</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </div>
            </header>

            <main
                id="main"
                tabIndex={-1}
                className="mx-auto max-w-5xl px-4 py-6 focus:outline-none sm:py-10"
            >
                {children}
            </main>
        </div>
    );
}
