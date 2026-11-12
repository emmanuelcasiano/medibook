import { Form, usePage } from '@inertiajs/react';
import { roleLabels } from '@/components/role-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { login as demoLogin } from '@/routes/demo';
import type { Role } from '@/types';

// What each demo role is good for, from the design guide. The server decides who these buttons log in as.
const accounts: { role: Role; hint: string }[] = [
    { role: 'patient', hint: 'Book, intake, cancel' },
    { role: 'staff', hint: 'Run the day schedule' },
    { role: 'doctor', hint: 'Intake and AI draft' },
    { role: 'nurse', hint: 'Triage queue' },
    { role: 'admin', hint: 'Setup and audit log' },
];

/**
 * One-click demo logins on the login page (PROJECT.md F12). Shown in demo mode only. Hiding them is UX: the route
 * itself is a 404 when demo mode is off. Each button is its own small form, so it must sit outside the login form.
 */
export default function DemoLogins() {
    const { demo } = usePage().props;

    if (!demo) {
        return null;
    }

    return (
        <section aria-label="Demo accounts" className="grid gap-4">
            <div className="flex items-center gap-3 text-sm text-muted-foreground">
                <span className="h-px flex-1 bg-border" aria-hidden="true" />
                or try a demo account
                <span className="h-px flex-1 bg-border" aria-hidden="true" />
            </div>

            <div className="grid grid-cols-2 gap-3">
                {accounts.map(({ role, hint }) => (
                    <Form key={role} {...demoLogin.form(role)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                                data-test={`demo-login-${role}`}
                                className="h-auto min-h-14 w-full flex-col items-start gap-0.5 px-3 py-2 text-left"
                            >
                                <span className="flex items-center gap-2 font-semibold">
                                    {processing && <Spinner />}
                                    {roleLabels[role]}
                                </span>
                                <span className="text-xs font-normal text-muted-foreground">
                                    {hint}
                                </span>
                            </Button>
                        )}
                    </Form>
                ))}
            </div>
        </section>
    );
}
