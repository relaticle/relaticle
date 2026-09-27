import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';

const root = fileURLToPath(new URL('../', import.meta.url));
const destination = path.join(root, 'public/brand');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'relaticle-brand-'));
const output = path.join(temporary, 'relaticle-brand-kit');
const check = process.argv.includes('--check');
const hash = (contents) => createHash('sha256').update(contents).digest('hex');
const inner = (svg) => svg.replace(/^[\s\S]*?<svg\b[^>]*>/, '').replace(/<\/svg>\s*$/, '');
const source = fs.readFileSync(path.join(destination, 'logomark.svg'), 'utf8');
const wordmark = fs.readFileSync(path.join(destination, 'wordmark.svg'), 'utf8');
const colorMark = inner(source);
const whiteMark = colorMark.replace(/\sopacity="[^"]*"/g, '').replace(/(fill|stroke)="url\(#[^"]*\)"/g, '$1="#FFFFFF"');
const scale = 1024 * 0.74 / 1158;
const offset = (1024 - 1158 * scale) / 2;
const placement = `translate(${offset} ${offset}) scale(${scale})`;
const variants = [
    { id: 'purple', background: '#5D54E8', mark: whiteMark },
    { id: 'light', background: '#FFFFFF', mark: colorMark },
    { id: 'dark', background: '#0F172A', mark: whiteMark },
];
const platforms = [
    ['YouTube', 'youtube', 800], ['X', 'x', 400], ['LinkedIn', 'linkedin', 400],
    ['Instagram', 'instagram', 1080], ['Facebook', 'facebook', 1080], ['Threads', 'threads', 1080],
    ['TikTok', 'tiktok', 1080], ['Reddit', 'reddit', 512], ['Discord', 'discord', 512],
    ['Pinterest', 'pinterest', 1080], ['Bluesky', 'bluesky', 1000], ['Mastodon', 'mastodon', 400],
    ['GitHub', 'github', 500], ['Telegram', 'telegram', 512], ['WhatsApp', 'whatsapp', 1080],
    ['Twitch', 'twitch', 800], ['Slack', 'slack', 512], ['Medium', 'medium', 400],
    ['Substack', 'substack', 1024], ['Product Hunt', 'product-hunt', 240],
].map(([name, id, size]) => ({ name, id, size, files: {} }));
const manifest = {
    sources: { 'logomark.svg': hash(source), 'wordmark.svg': hash(wordmark) },
    avatars: [], logos: [], platforms, watermarks: [], files: {},
};

function write(name, content) {
    const target = path.join(output, name);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, content);
    manifest.files[name] = hash(content);
    return name;
}

function svg(content, viewBox = '0 0 1024 1024', width = 1024, height = 1024) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="${viewBox}" fill="none">${content}</svg>\n`;
}

function render(master, name, width) {
    return write(name, execFileSync('rsvg-convert', ['-w', String(width), path.join(output, master)], {
        maxBuffer: 10 * 1024 * 1024,
    }));
}

try {
    execFileSync('rsvg-convert', ['--version']);
    execFileSync('zip', ['-v']);

    for (const variant of variants) {
        const artwork = svg(`<rect width="1024" height="1024" fill="${variant.background}"/><g transform="${placement}">${variant.mark}</g>`);
        const master = write(`avatars/${variant.id}.svg`, artwork);
        manifest.avatars.push({
            id: variant.id,
            svg: master,
            png: render(master, `avatars/${variant.id}-1024.png`, 1024),
            largePng: render(master, `avatars/${variant.id}-2048.png`, 2048),
        });
        for (const platform of platforms) {
            platform.files[variant.id] = render(master, `platforms/${variant.id}/${platform.id}-${platform.size}.png`, platform.size);
        }
    }

    for (const [variant, mark] of [['color', colorMark], ['white', whiteMark]]) {
        const symbol = write(`logos/symbol-${variant}.svg`, svg(mark, '-35 62 1228 1035', 1228, 1035));
        const wordColor = variant === 'white' ? '#FFFFFF' : '#0F172A';
        const glyphs = inner(wordmark).replace(/fill="#[^"]+"/g, `fill="${wordColor}"`);
        const lockup = write(`logos/lockup-${variant}.svg`, svg(
            `<g transform="scale(0.055268)">${mark}</g><g transform="translate(68 3.4) scale(0.0445)" stroke="${wordColor}" stroke-width="8" stroke-linejoin="round">${glyphs}</g>`,
            '-6 -4 252 72', 252, 72,
        ));
        manifest.logos.push(
            { id: `lockup-${variant}`, variant, kind: 'lockup', svg: lockup, png: render(lockup, `logos/lockup-${variant}-2520.png`, 2520) },
            { id: `symbol-${variant}`, variant, kind: 'symbol', svg: symbol, png: render(symbol, `logos/symbol-${variant}-2048.png`, 2048) },
        );
    }

    const watermark = write('watermarks/white.svg', svg(`<g transform="${placement}">${whiteMark}</g>`));
    manifest.watermarks.push(
        { id: 'white', png: render(watermark, 'watermarks/white-150.png', 150) },
        { id: 'purple', png: render('avatars/purple.svg', 'watermarks/purple-150.png', 150) },
    );
    write('README.txt', [
        'Relaticle brand kit', '',
        'Use the purple avatar consistently across social accounts.',
        'Upload the full square PNG. Platforms apply their own circular crop.',
        'The platform folders contain convenient export sizes, not permanent platform requirements.',
        'Preview the final crop in each platform uploader.', '',
        'Use color logos on light backgrounds and white logos on dark backgrounds.',
        'Logo PNGs have transparent backgrounds. SVGs scale without losing quality.',
        'Keep the original proportions, colors, and space around the artwork.',
        'Do not stretch, rotate, add effects, or imply endorsement by Relaticle.', '',
        'Brand assets and contact: https://relaticle.com/press',
        'Product screenshots are available separately on the press page.', '',
    ].join('\n'));
    write('manifest.json', JSON.stringify(manifest, null, 2) + '\n');

    const files = Object.keys(manifest.files).sort();
    for (const name of files) {
        fs.utimesSync(path.join(output, name), new Date('2000-01-01T00:00:00Z'), new Date('2000-01-01T00:00:00Z'));
        fs.chmodSync(path.join(output, name), 0o644);
    }
    execFileSync('zip', ['-X', '-q', path.join(temporary, 'kit.zip'), ...files.map((name) => `relaticle-brand-kit/${name}`)], {
        cwd: temporary, env: { ...process.env, TZ: 'UTC' },
    });

    if (check) {
        const expected = [...files.map((name) => [`kit/${name}`, path.join(output, name)]), ['kit.zip', path.join(temporary, 'kit.zip')]];
        const changed = expected.filter(([name, generated]) => {
            const existing = path.join(destination, name);
            return !fs.existsSync(existing) || hash(fs.readFileSync(existing)) !== hash(fs.readFileSync(generated));
        });
        const extras = fs.existsSync(path.join(destination, 'kit'))
            ? fs.readdirSync(path.join(destination, 'kit'), { recursive: true, withFileTypes: true })
                .filter((entry) => entry.isFile())
                .map((entry) => path.relative(path.join(destination, 'kit'), path.join(entry.parentPath, entry.name)))
                .filter((name) => !files.includes(name))
            : [];
        if (changed.length || extras.length) {
            throw new Error(`Brand assets need rebuilding: ${[...changed.map(([name]) => name), ...extras].join(', ')}`);
        }
        console.log(`Verified ${files.length} brand files and the ZIP against a fresh build.`);
    } else {
        fs.rmSync(path.join(destination, 'kit'), { recursive: true, force: true });
        fs.cpSync(output, path.join(destination, 'kit'), { recursive: true });
        fs.copyFileSync(path.join(temporary, 'kit.zip'), path.join(destination, 'kit.zip'));
        console.log(`Built ${files.length} brand files and public/brand/kit.zip.`);
    }
} finally {
    fs.rmSync(temporary, { recursive: true, force: true });
}
