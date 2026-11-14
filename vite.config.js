import {existsSync} from 'node:fs';
import {defineConfig, loadEnv} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

const cloudInputs = [
    'packages/Cloud/resources/css/cloud.css',
    'packages/Cloud/resources/js/motion.js',
    'packages/Cloud/resources/css/documentation.css',
    'packages/Cloud/resources/js/documentation.js',
    'packages/Cloud/resources/css/filament/app/theme.css',
    'packages/Cloud/resources/css/filament/admin/theme.css',
];

const richEditorPlugins = '/resources/js/filament/rich-content-plugins/';
const richEditorPackages = ['@tiptap/extension-list'];
const richEditorModules = {
    '@tiptap/core': 'core',
    '@tiptap/pm/state': 'pmState',
    '@tiptap/pm/model': 'pmModel',
    '@tiptap/pm/view': 'pmView',
};
const richEditorModulePrefix = '\0filament-tiptap:';

function filamentRichEditorModules() {
    const isRichEditorPackage = (id) => richEditorPackages.some((name) => id === name || id.startsWith(`${name}/`));
    const insideRichEditorPlugin = (importer) => importer.includes(richEditorPlugins)
        || richEditorPackages.some((name) => importer.includes(`/node_modules/${name}/`));

    return {
        name: 'filament-rich-editor-modules',
        enforce: 'pre',
        resolveId(id, importer) {
            if (!importer) {
                return null;
            }

            if (!insideRichEditorPlugin(importer)) {
                if (isRichEditorPackage(id)) {
                    this.error(`${importer} imports ${id}, which is built against Filament's editor. Import it only from ${richEditorPlugins}.`);
                }

                return null;
            }

            if (id in richEditorModules) {
                return richEditorModulePrefix + id;
            }

            if (/^(@tiptap\/(core|pm)|prosemirror-)/.test(id)) {
                this.error(`${importer} imports ${id}, a second TipTap or ProseMirror copy. Only ${Object.keys(richEditorModules).join(', ')} resolve to Filament's editor.`);
            }

            if (id.startsWith('@tiptap/') && !isRichEditorPackage(id)) {
                this.error(`${importer} imports ${id}. List it in richEditorPackages in vite.config.js, or it bundles its own TipTap.`);
            }

            return null;
        },
        async load(id) {
            if (!id.startsWith(richEditorModulePrefix)) {
                return null;
            }

            const specifier = id.slice(richEditorModulePrefix.length);
            const names = Object.keys(await import(specifier)).filter((name) => name !== 'default');

            return [
                `const pick = (name) => window.FilamentRichEditor.tiptap.${richEditorModules[specifier]}[name];`,
                ...names.map((name) => `export const ${name} = /* @__PURE__ */ pick(${JSON.stringify(name)});`),
            ].join('\n');
        },
        generateBundle(_options, bundle) {
            for (const chunk of Object.values(bundle)) {
                if (chunk.type === 'chunk' && chunk.facadeModuleId?.includes(richEditorPlugins) && !chunk.exports.includes('default')) {
                    this.error(`${chunk.fileName} has no default export, which Filament needs to load it as a rich editor plugin.`);
                }
            }
        },
    };
}

export default defineConfig(({mode}) => {
    const cloud = ['1', 'true', 'yes', 'on'].includes(String(loadEnv(mode, process.cwd(), '').RELATICLE_CLOUD ?? '').trim().toLowerCase())
        && existsSync(new URL('packages/Cloud', import.meta.url));

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
                    'resources/js/filament/rich-content-plugins/checklist.js',
                    'resources/js/filament/rich-content-plugins/email-composer.js',
                    // The cloud package is absent from self-hosted builds
                    ...(cloud ? cloudInputs : []),
                ],
                refresh: true,
            }),
            tailwindcss(),
            filamentRichEditorModules(),
        ],
        build: {
            rolldownOptions: {
                // Filament imports a rich editor plugin's default export, which an app build strips by default.
                preserveEntrySignatures: 'exports-only',
            },
        },
        // A pre-bundled copy would pull in its own TipTap instead of Filament's.
        optimizeDeps: {
            exclude: richEditorPackages,
        },
        resolve: {
            alias: {
                '@': '/resources/js',
            },
        },
    };
});
