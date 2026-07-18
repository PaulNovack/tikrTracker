import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            {/* Checkmark/line chart */}
            <path
                d="M3 17L7 13L11 17L21 7"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                fill="none"
            />

            {/* Upward arrow */}
            <path
                d="M17 3L21 3L21 7"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                fill="none"
            />
            <path
                d="M21 3L14 10"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                fill="none"
            />

            {/* Candlestick 1 */}
            <rect
                x="6"
                y="16"
                width="2"
                height="5"
                fill="currentColor"
                opacity="0.8"
            />
            <line
                x1="7"
                y1="15"
                x2="7"
                y2="21"
                stroke="currentColor"
                strokeWidth="0.5"
            />

            {/* Candlestick 2 */}
            <rect
                x="10"
                y="14"
                width="2"
                height="6"
                fill="currentColor"
                opacity="0.8"
            />
            <line
                x1="11"
                y1="13"
                x2="11"
                y2="21"
                stroke="currentColor"
                strokeWidth="0.5"
            />

            {/* Candlestick 3 */}
            <rect
                x="14"
                y="12"
                width="2"
                height="7"
                fill="currentColor"
                opacity="0.8"
            />
            <line
                x1="15"
                y1="11"
                x2="15"
                y2="21"
                stroke="currentColor"
                strokeWidth="0.5"
            />

            {/* Candlestick 4 */}
            <rect
                x="18"
                y="10"
                width="2"
                height="8"
                fill="currentColor"
                opacity="0.8"
            />
            <line
                x1="19"
                y1="9"
                x2="19"
                y2="21"
                stroke="currentColor"
                strokeWidth="0.5"
            />
        </svg>
    );
}
