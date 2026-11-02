import type { SVGAttributes } from 'react';

/** The MediBook plus sign. It draws with `currentColor`, so the parent sets its color. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 28 28"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden
        >
            <path
                d="M14 7v14M7 14h14"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
            />
        </svg>
    );
}
