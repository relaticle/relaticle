import {existsSync} from 'node:fs';
import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const cloudAppTheme = 'packages/Cloud/resources/css/filament/app/theme.css';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Marketing website
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/motion.js',
                // Passkeys client (loaded on demand by Blade components)
                'resources/js/passkeys.js',
                // Chat
                'packages/Chat/resources/js/chat.js',
                // Filament
                'resources/css/filament/app/theme.css',
                'resources/css/filament/admin/theme.css',
                // Documentation
                'packages/Documentation/resources/css/documentation.css',
                'packages/Documentation/resources/js/documentation.js',
                // The cloud package is absent from self-hosted builds
                ...(existsSync(new URL(cloudAppTheme, import.meta.url)) ? [cloudAppTheme] : []),
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
