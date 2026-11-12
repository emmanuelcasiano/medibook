import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock, Stethoscope } from 'lucide-react';
import DoctorController from '@/actions/App/Http/Controllers/Booking/DoctorController';
import BookingSteps from '@/components/booking/booking-steps';
import EmptyState from '@/components/empty-state';
import PageHeader from '@/components/page-header';
import type { BookableService } from '@/types';

type Props = {
    services: BookableService[];
};

export default function BookingServices({ services }: Props) {
    return (
        <>
            <Head title="Book an appointment" />

            <div className="space-y-6">
                <BookingSteps current={1} />

                <PageHeader
                    title="What would you like to be seen for?"
                    description="No account is needed to look. You log in or register just before you confirm."
                />

                {services.length === 0 ? (
                    <EmptyState icon={Stethoscope}>
                        No services are open for booking right now. Please check
                        back later.
                    </EmptyState>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {services.map((service) => (
                            <li key={service.slug}>
                                <Link
                                    href={DoctorController.index(service)}
                                    className="group flex h-full items-start gap-4 rounded-xl border bg-card p-4 outline-none hover:border-teal-line hover:bg-teal-soft/40 focus-visible:ring-[3px] focus-visible:ring-ring"
                                >
                                    <span
                                        aria-hidden
                                        className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-teal-soft text-teal-ink"
                                    >
                                        <Stethoscope className="size-6" />
                                    </span>
                                    <span className="min-w-0 flex-1 space-y-1.5">
                                        <span className="block text-[17px] font-semibold">
                                            {service.name}
                                        </span>
                                        {service.description && (
                                            <span className="block text-sm text-muted-foreground">
                                                {service.description}
                                            </span>
                                        )}
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                            <Clock
                                                aria-hidden
                                                className="size-3.5"
                                            />
                                            {service.durationMinutes} min
                                        </span>
                                    </span>
                                    <ChevronRight
                                        aria-hidden
                                        className="mt-3 size-5 shrink-0 text-muted-foreground group-hover:text-teal-ink"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}
