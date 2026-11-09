import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            demo: boolean;
            auth: Auth;
            sidebarOpen: boolean;
            /** How many follow-ups the signed-in patient can book. 0 for every other role. */
            openFollowUps: number;
            [key: string]: unknown;
        };
    }
}
