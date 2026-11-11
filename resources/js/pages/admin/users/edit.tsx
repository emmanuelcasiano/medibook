import { Form, Head, Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import FormCard from '@/components/admin/form-card';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminUserFormValues } from '@/types';

type Props = {
    user: AdminUserFormValues;
};

export default function AdminUsersEdit({ user }: Props) {
    return (
        <>
            <Head title={`Edit ${user.name}`} />

            <div className="max-w-xl space-y-6 p-4">
                <PageHeader
                    title={`Edit ${user.name}`}
                    description="Correct the name or the email address. If the email changes, the account is unverified until the person clicks the link we send to the new address."
                />

                <FormCard>
                    <Form
                        {...UserController.update.form(user.id)}
                        // A new key resets the fields if the admin opens another user.
                        key={user.id}
                        disableWhileProcessing
                        className="grid gap-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        defaultValue={user.name}
                                        required
                                        autoFocus
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email address</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={user.email}
                                        required
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-1">
                                    <span className="text-sm font-medium">
                                        Role
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {user.roleLabel}. The role and password
                                        are not changed here.
                                    </span>
                                </div>

                                <div className="flex items-center gap-3">
                                    <Button type="submit">
                                        {processing && <Spinner />}
                                        Save changes
                                    </Button>
                                    <Button asChild variant="ghost">
                                        <Link href={UserController.index()}>
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </FormCard>
            </div>
        </>
    );
}

AdminUsersEdit.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Edit user', href: UserController.index() },
    ],
};
