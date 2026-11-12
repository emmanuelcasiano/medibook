import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            position="bottom-right"
            richColors
            // The icon carries the meaning next to the colour, so a toast is not told apart by colour alone.
            icons={{
                success: <CircleCheck aria-hidden className="size-4" />,
                error: <CircleAlert aria-hidden className="size-4" />,
                warning: <TriangleAlert aria-hidden className="size-4" />,
                info: <Info aria-hidden className="size-4" />,
            }}
            // Every colour comes from the status tokens, which have a light and a dark value, so a toast reads in both.
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--success-bg': 'var(--teal-soft)',
                    '--success-text': 'var(--teal-ink)',
                    '--success-border': 'var(--teal-line)',
                    '--error-bg': 'var(--danger-soft)',
                    '--error-text': 'var(--danger-ink)',
                    '--error-border': 'var(--danger-line)',
                    '--warning-bg': 'var(--amber-soft)',
                    '--warning-text': 'var(--amber-ink)',
                    '--warning-border': 'var(--amber-line)',
                    '--info-bg': 'var(--info-soft)',
                    '--info-text': 'var(--info-ink)',
                    '--info-border': 'var(--info-line)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
