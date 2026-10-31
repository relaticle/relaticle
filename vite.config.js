import {existsSync} from 'node:fs';
import {defineConfig, loadEnv} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const cloudInputs = (fossOnly) => fossOnly ? [] : [
    'packages/Cloud/resources/css/cloud.css',
    'packages/Cloud/resources/js/motion.js',
    'packages/Cloud/resources/css/documentation.css',
    'packages/Cloud/resources/js/documentation.js',
    'packages/Cloud/resources/css/filament/app/theme.css',
    'packages/Cloud/resources/css/filament/admin/theme.css',
].filter((input) => existsSync(new URL(input, import.meta.url)));

export default defineConfig(({mode}) => {
    const fossOnly = ['1', 'true'].includes(String(loadEnv(mode, process.cwd(), '').RELATICLE_FOSS_ONLY ?? '').toLowerCase());

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                    // Passkeys client (loaded on demand by Blade components)
                    'resources/js/passkeys.js',
                    // Chat
                    'packages/Chat/resources/js/chat.js',
                    // Filament
                    'resources/css/filament/app/theme.css',
                    'resources/css/filament/admin/theme.css',
                    // The cloud package is absent from self-hosted builds
                    ...cloudInputs(fossOnly),
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
    };
});
