import { Form, Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import RoleBadge from '@/components/admin/role-badge';
import StatePill from '@/components/admin/state-pill';
import { ActionsTd, DataTable, Pager, Td, Th } from '@/components/data-table';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type {
    AdminUserFilters,
    AdminUserListItem,
    Paginated,
    RoleOption,
} from '@/types';

type Props = {
    users: Paginated<AdminUserListItem>;
    filters: AdminUserFilters;
    roles: RoleOption[];
};

/**
 * A plain GET form, so it works without JavaScript and the filtered address can be bookmarked or shared. Inertia takes
 * over the submit when JavaScript is on. Two details:
 * - `preserveState` keeps the form mounted across the visit, so a validation error can show next to its field.
 * - The inputs are uncontrolled (`defaultValue`), and the page keys this component on the applied filters, so "Clear"
 *   resets them.
 */
function UserFilterForm({
    filters,
    roles,
}: {
    filters: AdminUserFilters;
    roles: RoleOption[];
}) {
    const hasFilters = Object.values(filters).some((value) => value !== '');

    return (
        <Form
            {...UserController.index.form()}
            options={{ preserveState: true }}
            role="search"
            className="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="q">Search</Label>
                        <Input
                            id="q"
                            name="q"
                            type="search"
                            maxLength={100}
                            placeholder="Name or email"
                            defaultValue={filters.q}
                            aria-invalid={errors.q ? true : undefined}
                        />
                        <InputError message={errors.q} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <NativeSelect
                            id="role"
                            name="role"
                            defaultValue={filters.role}
                            aria-invalid={errors.role ? true : undefined}
                        >
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.value} value={role.value}>
                                    {role.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            aria-invalid={errors.status ? true : undefined}
                        >
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </NativeSelect>
                        <InputError message={errors.status} />
                    </div>

                    <div className="flex items-end gap-3">
                        <Button type="submit" disabled={processing}>
                            Search
                        </Button>
                        {hasFilters && (
                            <Button asChild variant="ghost">
                                <Link href={UserController.index()}>Clear</Link>
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

export default function AdminUsersIndex({ users, filters, roles }: Props) {
    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Users"
                    description="Create staff, doctor, nurse and admin accounts, edit a name or email, or deactivate an account. Patients register themselves."
                    actions={
                        <Button asChild>
                            <Link href={UserController.create()}>Add user</Link>
                        </Button>
                    }
                />

                <UserFilterForm
                    key={JSON.stringify(filters)}
                    filters={filters}
                    roles={roles}
                />

                {users.data.length === 0 ? (
                    <div className="rounded-xl border bg-card px-4 py-10 text-center">
                        <p className="text-muted-foreground">
                            No users match these filters
                        </p>
                        <Button asChild variant="outline" className="mt-4">
                            <Link href={UserController.index()}>Clear</Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        <DataTable caption="User accounts">
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
                                                    user.isActive
                                                        ? 'good'
                                                        : 'neutral'
                                                }
                                            >
                                                {user.isActive
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </StatePill>
                                        </Td>
                                        <ActionsTd>
                                            {user.can.update && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={UserController.edit(
                                                            user.id,
                                                        )}
                                                    >
                                                        Edit
                                                        <span className="sr-only">
                                                            {' '}
                                                            {user.name}
                                                        </span>
                                                    </Link>
                                                </Button>
                                            )}
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
                                                            variant="destructive-outline"
                                                            disabled={
                                                                processing
                                                            }
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
                                            {user.can.reactivate && (
                                                <Form
                                                    {...UserController.reactivate.form(
                                                        user.id,
                                                    )}
                                                    onBefore={() =>
                                                        window.confirm(
                                                            `Reactivate ${user.name}? They will be able to log in again.`,
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
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Reactivate
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
                    </>
                )}
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
