import { Link, usePage } from '@inertiajs/react';
import AppLogoMark from '@/components/app-logo-mark';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 rounded-lg font-medium focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <AppLogoMark className="size-12 rounded-xl" />
                            {/* The link goes to the home page, so its name is the app, not the page title. */}
                            <span className="sr-only">{name}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
