import { usePage } from '@inertiajs/react';

/**
 * The fake-data notice (PROJECT.md F12). Every layout renders it, and it shows only in demo mode, so a real install
 * has no banner. The text is the project's standard wording.
 */
export default function DemoBanner() {
    const { demo } = usePage().props;

    if (!demo) {
        return null;
    }

    return (
        <p className="bg-deep px-6 py-2 text-center text-[13px] leading-[18px] text-deep-foreground">
            Demo with fake data. Do not enter real health information.
        </p>
    );
}
