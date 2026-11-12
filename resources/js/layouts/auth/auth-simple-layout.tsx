import { Link, usePage } from '@inertiajs/react';
import AppLogoMark from '@/components/app-logo-mark';
import DemoBanner from '@/components/demo-banner';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Every login, registration and password screen: a deep-teal brand side and the form on a white side. The brand side
 * only repeats what the app is for, so it is hidden below 768 px and a phone gets the logo above the form instead.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col">
            <DemoBanner />
            <div className="grid flex-1 md:grid-cols-2">
                <aside className="hidden flex-col justify-between gap-12 bg-deep p-12 text-deep-foreground md:flex lg:p-16">
                    <Link
                        href={home()}
                        className="flex w-fit items-center gap-3 rounded-md text-lg font-semibold text-white focus-visible:ring-[3px] focus-visible:ring-mint focus-visible:outline-none"
                    >
                        <AppLogoMark className="size-9 bg-mint text-deep" />
                        {name}
                    </Link>

                    {/* Not a heading: the form's title below is the one `h1` of the page. */}
                    <div className="space-y-5">
                        <p className="max-w-lg text-4xl leading-tight font-semibold tracking-tight text-white lg:text-[42px] lg:leading-[48px]">
                            Your clinic visit, booked and ready before you
                            arrive.
                        </p>
                        <p className="max-w-md text-base leading-7 text-deep-muted">
                            Pick a time with the doctor you need, tell us why
                            you're coming in, and your doctor reads it before
                            the visit.
                        </p>
                    </div>

                    <p className="text-sm text-deep-muted">
                        One clinic · All times in Manila time · Demo with fake
                        data
                    </p>
                </aside>

                <main className="flex items-center justify-center bg-card p-6 md:p-12">
                    <div className="flex w-full max-w-sm flex-col gap-8">
                        <div className="flex flex-col gap-6">
                            {/* The brand side is hidden on a phone, so the logo moves here. */}
                            <Link
                                href={home()}
                                className="w-fit rounded-lg focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none md:hidden"
                            >
                                <AppLogoMark className="size-11 rounded-xl" />
                                {/* The link goes to the home page, so its name is the app, not the page title. */}
                                <span className="sr-only">{name}</span>
                            </Link>

                            <div className="space-y-2">
                                <h1 className="text-[28px] leading-9 font-semibold tracking-tight">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="text-sm text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        </div>
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
