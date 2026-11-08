import { useId } from 'react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    children: ReactNode;
};

/** One titled card of the intake. The form and the read-only view both use it, so they have the same layout. */
export default function IntakeSection({ title, children }: Props) {
    const headingId = useId();

    return (
        <section
            aria-labelledby={headingId}
            className="space-y-5 rounded-xl border bg-card p-5"
        >
            <h2 id={headingId} className="text-base font-semibold">
                {title}
            </h2>
            {children}
        </section>
    );
}
