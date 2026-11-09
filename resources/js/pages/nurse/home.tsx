import { Head } from '@inertiajs/react';
import { HeartPulse } from 'lucide-react';
import PageHeader from '@/components/page-header';
import { home as nurseHome } from '@/routes/nurse';

export default function NurseHome() {
    return (
        <>
            <Head title="Triage" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Triage"
                    description="Patients who have checked in and are waiting for vitals appear here."
                />

                <div className="flex flex-col items-center gap-3 rounded-xl border bg-card p-8 text-center">
                    <HeartPulse
                        aria-hidden
                        className="size-7 text-muted-foreground"
                    />
                    <p className="font-medium">
                        No patients are waiting for vitals.
                    </p>
                </div>
            </div>
        </>
    );
}

NurseHome.layout = {
    breadcrumbs: [
        {
            title: 'Triage',
            href: nurseHome(),
        },
    ],
};
