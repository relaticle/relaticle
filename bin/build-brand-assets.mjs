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
const siteOutput = path.join(temporary, 'site');
const check = process.argv.includes('--check');
const hash = (contents) => createHash('sha256').update(contents).digest('hex');
const inner = (svg) => svg.replace(/^[\s\S]*?<svg\b[^>]*>/, '').replace(/<\/svg>\s*$/, '');
const source = fs.readFileSync(path.join(destination, 'logomark.svg'), 'utf8');
const wordmark = fs.readFileSync(path.join(destination, 'wordmark.svg'), 'utf8');
const colorMark = inner(source);
const whiteMark = colorMark.replace(/\sopacity="[^"]*"/g, '').replace(/(fill|stroke)="(?!none")[^"]*"/g, '$1="#FFFFFF"');
const placed = (fraction) => {
    const scale = 1024 * fraction / 1158;
    const offset = (1024 - 1158 * scale) / 2;

    return `translate(${offset} ${offset}) scale(${scale})`;
};
const placement = placed(0.74);
const brandColor = '#5D54E8';
const variants = [
    { id: 'purple', background: brandColor, mark: whiteMark },
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
const siteFiles = [];

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

function writeSite(name, content) {
    fs.mkdirSync(siteOutput, { recursive: true });
    fs.writeFileSync(path.join(siteOutput, name), content);
    siteFiles.push(name);
}

function rasterize(artwork, width) {
    return execFileSync('rsvg-convert', ['-w', String(width)], { input: artwork, maxBuffer: 10 * 1024 * 1024 });
}

function ico(sizes, artwork) {
    const images = sizes.map((size) => rasterize(artwork, size));
    const header = Buffer.alloc(6 + 16 * images.length);
    let offset = header.length;

    header.writeUInt16LE(1, 2);
    header.writeUInt16LE(images.length, 4);
    images.forEach((image, index) => {
        const entry = 6 + 16 * index;
        header.writeUInt8(sizes[index], entry);
        header.writeUInt8(sizes[index], entry + 1);
        header.writeUInt16LE(1, entry + 4);
        header.writeUInt16LE(32, entry + 6);
        header.writeUInt32LE(image.length, entry + 8);
        header.writeUInt32LE(offset, entry + 12);
        offset += image.length;
    });

    return Buffer.concat([header, ...images]);
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
        const inked = inner(wordmark).replaceAll('#0F172A', wordColor);
        const glyphs = variant === 'white' ? inked.replaceAll(brandColor, '#FFFFFF') : inked;
        const lockup = write(`logos/lockup-${variant}.svg`, svg(
            `<g transform="scale(0.055268)">${mark}</g><g transform="translate(68 3.4) scale(0.0445)" stroke="${wordColor}" stroke-width="8" stroke-linejoin="round">${glyphs}</g>`,
            '-6 -4 252 72', 252, 72,
        ));
        manifest.logos.push(
            { id: `lockup-${variant}`, variant, kind: 'lockup', svg: lockup, png: render(lockup, `logos/lockup-${variant}-2520.png`, 2520) },
            { id: `symbol-${variant}`, variant, kind: 'symbol', svg: symbol, png: render(symbol, `logos/symbol-${variant}-2048.png`, 2048) },
        );
    }

    const watermark = write('watermarks/white.svg', svg(`<g transform="${placed(0.92)}">${whiteMark}</g>`));
    manifest.watermarks.push(
        { id: 'white', png: render(watermark, 'watermarks/white-150.png', 150) },
        { id: 'purple', png: render('avatars/purple.svg', 'watermarks/purple-150.png', 150) },
    );
    const favicon = svg(colorMark, '-24 -24 1206 1206', 1206, 1206);
    const appIcon = fs.readFileSync(path.join(output, 'avatars/purple.svg'));
    const maskableIcon = svg(`<rect width="1024" height="1024" fill="${brandColor}"/><g transform="${placed(0.6)}">${whiteMark}</g>`);
    writeSite('favicon.svg', favicon);
    writeSite('favicon-96x96.png', rasterize(favicon, 96));
    writeSite('favicon.ico', ico([16, 32, 48], favicon));
    writeSite('apple-touch-icon.png', rasterize(appIcon, 180));
    for (const size of [192, 512]) {
        writeSite(`web-app-manifest-${size}x${size}.png`, rasterize(appIcon, size));
        writeSite(`web-app-manifest-maskable-${size}x${size}.png`, rasterize(maskableIcon, size));
    }

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
        const expected = [
            ...files.map((name) => [`brand/kit/${name}`, path.join(output, name)]),
            ['brand/kit.zip', path.join(temporary, 'kit.zip')],
            ...siteFiles.map((name) => [name, path.join(siteOutput, name)]),
        ];
        const changed = expected.filter(([name, generated]) => {
            const existing = path.join(root, 'public', name);
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
        console.log(`Verified ${files.length} brand files, the ZIP and ${siteFiles.length} site icons against a fresh build.`);
    } else {
        fs.rmSync(path.join(destination, 'kit'), { recursive: true, force: true });
        fs.cpSync(output, path.join(destination, 'kit'), { recursive: true });
        fs.copyFileSync(path.join(temporary, 'kit.zip'), path.join(destination, 'kit.zip'));
        for (const name of siteFiles) {
            fs.copyFileSync(path.join(siteOutput, name), path.join(root, 'public', name));
        }
        console.log(`Built ${files.length} brand files, public/brand/kit.zip and ${siteFiles.length} site icons.`);
    }
} finally {
    fs.rmSync(temporary, { recursive: true, force: true });
}
