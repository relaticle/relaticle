import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const root = fileURLToPath(new URL('../', import.meta.url));
const directory = path.join(root, 'resources/js/filament/rich-content-plugins');
const destination = path.join(directory, 'dist/checklist.js');
const check = process.argv.includes('--check');
const maxBytes = 40 * 1024;
const shared = {
    '@tiptap/core': 'core',
    '@tiptap/pm/state': 'pmState',
    '@tiptap/pm/model': 'pmModel',
    '@tiptap/pm/view': 'pmView',
};
const prefix = '\0filament-tiptap:';

async function filamentTiptap() {
    const modules = {};
    for (const [specifier, key] of Object.entries(shared)) {
        const names = Object.keys(await import(specifier)).filter((name) => name !== 'default');
        modules[specifier] = names
            .map((name) => `export const ${name} = window.FilamentRichEditor.tiptap.${key}.${name};`)
            .join('\n');
    }

    return {
        name: 'filament-tiptap',
        enforce: 'pre',
        resolveId(id) {
            if (id in shared) {
                return prefix + id;
            }

            if (/^(@tiptap\/(core|pm)|prosemirror-)/.test(id)) {
                this.error(`${id} would bundle a second TipTap or ProseMirror copy. Only ${Object.keys(shared).join(', ')} resolve to the editor's own.`);
            }

            return null;
        },
        load: (id) => (id.startsWith(prefix) ? modules[id.slice(prefix.length)] : null),
    };
}

const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'relaticle-rich-editor-'));

try {
    await build({
        root,
        configFile: false,
        publicDir: false,
        logLevel: 'warn',
        plugins: [await filamentTiptap()],
        build: {
            outDir: temporary,
            emptyOutDir: true,
            minify: true,
            rolldownOptions: { treeshake: { propertyReadSideEffects: false } },
            lib: { entry: path.join(directory, 'checklist.js'), formats: ['es'], fileName: () => 'checklist.js' },
        },
    });

    const output = path.join(temporary, 'checklist.js');
    const built = fs.readFileSync(output);

    if (built.length > maxBytes) {
        throw new Error(`checklist.js is ${built.length} bytes, over ${maxBytes}. A second TipTap copy was probably bundled.`);
    }

    if (/prosemirror/i.test(built.toString())) {
        throw new Error('checklist.js contains ProseMirror code. It must come from window.FilamentRichEditor.tiptap.');
    }

    if (check) {
        if (!fs.existsSync(destination) || !fs.readFileSync(destination).equals(built)) {
            throw new Error('resources/js/filament/rich-content-plugins/dist/checklist.js needs rebuilding: run pnpm run rich-editor:build.');
        }
        console.log(`Verified checklist.js (${built.length} bytes) against a fresh build.`);
    } else {
        fs.mkdirSync(path.dirname(destination), { recursive: true });
        fs.copyFileSync(output, destination);
        console.log(`Built checklist.js (${built.length} bytes).`);
    }
} finally {
    fs.rmSync(temporary, { recursive: true, force: true });
}
