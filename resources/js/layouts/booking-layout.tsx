import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, register } from '@/routes';
import { home as myHome } from '@/routes/my';

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
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 rounded-md font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <AppLogoIcon className="size-6 fill-current" />
                        <span>{name}</span>
                    </Link>

                    <nav
                        aria-label="Account"
                        className="flex items-center gap-2"
                    >
                        {auth.user ? (
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={
                                        auth.user.role === 'patient'
                                            ? myHome()
                                            : dashboard()
                                    }
                                >
                                    {auth.user.role === 'patient'
                                        ? 'My appointments'
                                        : 'Dashboard'}
                                </Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={login()}>Log in</Link>
                                </Button>
                                <Button asChild variant="outline" size="sm">
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
