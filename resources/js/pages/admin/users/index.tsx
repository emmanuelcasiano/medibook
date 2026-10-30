import { Form, Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { AdminUserListItem, Paginated } from '@/types';

type Props = {
    users: Paginated<AdminUserListItem>;
};

export default function AdminUsersIndex({ users }: Props) {
    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Users"
                        description="Create staff, doctor and admin accounts, or deactivate an account. Patients register themselves."
                    />
                    <Button asChild>
                        <Link href={UserController.create()}>Add user</Link>
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <caption className="sr-only">All user accounts</caption>
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    2FA
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 font-medium"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-3 text-right font-medium"
                                >
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-4 py-3">
                                        <div className="font-medium">
                                            {user.name}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge variant="outline">
                                            {user.roleLabel}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3">
                                        {user.twoFactorEnabled ? 'On' : 'Off'}
                                    </td>
                                    <td className="px-4 py-3">
                                        <Badge
                                            variant={
                                                user.isActive
                                                    ? 'secondary'
                                                    : 'destructive'
                                            }
                                        >
                                            {user.isActive
                                                ? 'Active'
                                                : 'Deactivated'}
                                        </Badge>
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {user.can.deactivate && (
                                            <Form
                                                {...UserController.deactivate.form(
                                                    user.id,
                                                )}
                                                onBefore={() =>
                                                    window.confirm(
                                                        `Deactivate ${user.name}? They will be signed out and can no longer log in.`,
                                                    )
                                                }
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Deactivate
                                                        <span className="sr-only">
                                                            {' '}
                                                            {user.name}
                                                        </span>
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <nav
                    className="flex items-center justify-between text-sm"
                    aria-label="Pagination"
                >
                    <p className="text-muted-foreground">
                        {users.from !== null
                            ? `Showing ${users.from} to ${users.to} of ${users.total}`
                            : 'No users'}
                    </p>
                    <div className="flex gap-2">
                        {users.prev_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={users.prev_page_url} preserveScroll>
                                    Previous
                                </Link>
                            </Button>
                        ) : null}
                        {users.next_page_url ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={users.next_page_url} preserveScroll>
                                    Next
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                </nav>
            </div>
        </>
    );
}

AdminUsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: UserController.index(),
        },
    ],
};
