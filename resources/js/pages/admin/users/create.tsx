import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Admin/UserController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Role, RoleOption } from '@/types';

type Props = {
    roles: RoleOption[];
};

export default function AdminUsersCreate({ roles }: Props) {
    const [role, setRole] = useState<Role | ''>('');

    return (
        <>
            <Head title="Add user" />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title="Add user"
                    description="The account starts verified. Share the password with the person safely. They must turn on two-factor authentication at first login."
                />

                <Form
                    {...UserController.store.form()}
                    resetOnSuccess={['password', 'password_confirmation']}
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
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="role">Role</Label>
                                <select
                                    id="role"
                                    name="role"
                                    required
                                    value={role}
                                    onChange={(e) =>
                                        setRole(e.target.value as Role | '')
                                    }
                                    aria-invalid={
                                        errors.role ? true : undefined
                                    }
                                    className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <option value="" disabled>
                                        Choose a role
                                    </option>
                                    {roles.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.role} />
                            </div>

                            {role === 'doctor' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="specialty">Specialty</Label>
                                    <Input
                                        id="specialty"
                                        name="specialty"
                                        required
                                        autoComplete="off"
                                        placeholder="For example, Pediatrics"
                                    />
                                    <InputError message={errors.specialty} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-3">
                                <Button type="submit">
                                    {processing && <Spinner />}
                                    Create user
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
            </div>
        </>
    );
}

AdminUsersCreate.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Add user', href: UserController.create() },
    ],
};
