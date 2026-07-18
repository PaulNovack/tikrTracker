import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Set up Pusher for Echo
(window as any).Pusher = Pusher;

// Create Echo instance directly
const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    forceTLS: false,
});

// Debug the connection
echo.connector.pusher.connection.bind('connected', () => {
    console.log('✅ Echo WebSocket connected successfully!');
});

echo.connector.pusher.connection.bind('disconnected', () => {
    console.log('❌ Echo WebSocket disconnected');
});

echo.connector.pusher.connection.bind('error', (error: any) => {
    console.log('🚨 Echo WebSocket error:', error);
});

// Make Echo available globally
(window as any).Echo = echo;

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <App {...props} />
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
