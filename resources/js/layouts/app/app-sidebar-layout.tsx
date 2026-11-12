import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import DemoBanner from '@/components/demo-banner';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            {/* The first Tab stop of every signed-in page, so the sidebar is not tabbed through each time. */}
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:ring-2 focus:ring-ring"
            >
                Skip to the page content
            </a>
            <AppSidebar />
            <AppContent
                variant="sidebar"
                id="main"
                tabIndex={-1}
                className="min-w-0 overflow-x-clip focus:outline-none"
            >
                <DemoBanner />
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
