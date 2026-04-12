import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import path from 'path';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                // Marketing website
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/motion.js',
                // Filament
                'resources/css/filament/app/theme.css',
                'resources/css/filament/admin/theme.css',
                // Documentation
                'packages/Documentation/resources/css/documentation.css',
                'packages/Documentation/resources/js/documentation.js',
            ],
            refresh: [
                'resources/views/**',
                'routes/**',
                'packages/*/resources/views/**',
            ],
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
            '~': path.resolve(__dirname, './resources'),
        },
    },
});
