import { usePage } from '@inertiajs/react';

import AppLogoMark from '@/components/app-logo-mark';

export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoMark />
            <div className="ml-1 grid flex-1 text-left text-base">
                <span className="truncate leading-tight font-semibold tracking-tight">
                    {name}
                </span>
            </div>
        </>
    );
}
