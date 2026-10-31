import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';

type Props = {
    title: string;
    note: string;
};

/** Landing page for the /staff and /doctor groups until their real screens are built. */
export default function RoleHome({ title, note }: Props) {
    return (
        <>
            <Head title={title} />

            <div className="space-y-6 p-4">
                <Heading title={title} description={note} />
            </div>
        </>
    );
}
