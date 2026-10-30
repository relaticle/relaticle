import {existsSync} from 'node:fs';
import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const cloudInputs = [
    'packages/Cloud/resources/css/cloud.css',
    'packages/Cloud/resources/css/documentation.css',
    'packages/Cloud/resources/js/documentation.js',
    'packages/Cloud/resources/css/filament/app/theme.css',
    'packages/Cloud/resources/css/filament/admin/theme.css',
].filter((input) => existsSync(new URL(input, import.meta.url)));

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
                // The cloud package is absent from self-hosted builds
                ...cloudInputs,
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
