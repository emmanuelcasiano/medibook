import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import DoctorController from '@/actions/App/Http/Controllers/Booking/DoctorController';
import BookingSteps from '@/components/booking/booking-steps';
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

                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        What would you like to be seen for?
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        No account is needed to look. You log in or register
                        just before you confirm.
                    </p>
                </div>

                {services.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">
                        No services are open for booking right now. Please check
                        back later.
                    </p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {services.map((service) => (
                            <li key={service.slug}>
                                <Link
                                    href={DoctorController.index(service)}
                                    className="flex h-full items-center justify-between gap-3 rounded-xl border bg-card p-4 outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <span className="space-y-1">
                                        <span className="block font-medium">
                                            {service.name}
                                        </span>
                                        {service.description && (
                                            <span className="block text-sm text-muted-foreground">
                                                {service.description}
                                            </span>
                                        )}
                                        <span className="block text-sm text-muted-foreground">
                                            {service.durationMinutes} min
                                        </span>
                                    </span>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-5 shrink-0 text-muted-foreground"
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
