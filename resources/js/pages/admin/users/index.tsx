import { Form, Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import RoleBadge from '@/components/admin/role-badge';
import StatePill from '@/components/admin/state-pill';
import { ActionsTd, DataTable, Pager, Td, Th } from '@/components/data-table';
import PageHeader from '@/components/page-header';
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
                <PageHeader
                    title="Users"
                    description="Create staff, doctor, nurse and admin accounts, or deactivate an account. Patients register themselves."
                    actions={
                        <Button asChild>
                            <Link href={UserController.create()}>Add user</Link>
                        </Button>
                    }
                />

                <DataTable caption="All user accounts">
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Role</Th>
                            <Th>2FA</Th>
                            <Th>Status</Th>
                            <Th srOnly>Actions</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {users.data.map((user) => (
                            <tr key={user.id}>
                                <Td>
                                    <div className="font-medium">
                                        {user.name}
                                    </div>
                                    <div className="text-muted-foreground">
                                        {user.email}
                                    </div>
                                </Td>
                                <Td>
                                    <RoleBadge
                                        role={user.role}
                                        label={user.roleLabel}
                                    />
                                </Td>
                                <Td>
                                    {/* Staff, doctor, nurse and admin accounts must have 2FA, so "off" stands out for them. */}
                                    <StatePill
                                        small
                                        tone={
                                            user.twoFactorEnabled ||
                                            user.role === 'patient'
                                                ? 'neutral'
                                                : 'warning'
                                        }
                                    >
                                        {user.twoFactorEnabled
                                            ? '2FA on'
                                            : '2FA off'}
                                    </StatePill>
                                </Td>
                                <Td>
                                    <StatePill
                                        tone={
                                            user.isActive ? 'good' : 'neutral'
                                        }
                                    >
                                        {user.isActive ? 'Active' : 'Inactive'}
                                    </StatePill>
                                </Td>
                                <ActionsTd>
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
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="destructive-outline"
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
                                </ActionsTd>
                            </tr>
                        ))}
                    </tbody>
                </DataTable>

                <Pager paginator={users} empty="No users" />
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
