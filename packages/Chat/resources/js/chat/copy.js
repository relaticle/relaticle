// Plain-text serialization of a rendered assistant reply, for the copy button.
//
// `msg.content` is markdown while a reply streams and server-rendered HTML
// once it comes back on a reload (see `prerendered` in transcript.js), so
// copying `content` verbatim pasted raw `<p>` tags for every message the
// reader had not personally watched arrive. The transcript's own segment plan
// (messageSegments) already hands both shapes over as HTML, so copy runs one
// path: HTML in, structured plain text out.
//
// Hand-rolled rather than pulled from a package because the tag set is
// bounded. A reply is produced by CommonMark core plus the table extension
// (MarkdownRenderer) or by marked plus DOMPurify (window.renderMarkdown), and
// the only bespoke markup either emits is the record-chip anchor.

const ELEMENT_NODE = 1;
const TEXT_NODE = 3;

const INLINE_WRAPPERS = { strong: '**', b: '**', em: '_', i: '_', del: '~~', s: '~~' };

// Everything either markdown pipeline can emit at block level, plus the
// generic containers the renderers wrap things in (`chat-md-table`).
const BLOCK_TAGS = new Set([
    'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
    'blockquote', 'pre', 'hr', 'table', 'div', 'section', 'figure',
]);

const tagOf = (node) => (node.nodeType === ELEMENT_NODE ? node.tagName.toLowerCase() : '');

// HTML collapses whitespace runs when it paints, so the copy has to as well:
// the newlines a renderer leaves between tags are layout, not content, and
// carrying them through would break sentences mid-line.
const collapse = (text) => text.replace(/\s+/g, ' ');

const inline = (node) => {
    if (node.nodeType === TEXT_NODE) return collapse(node.nodeValue ?? '');
    if (node.nodeType !== ELEMENT_NODE) return '';

    const tag = tagOf(node);

    // The chip and heading glyphs are drawn, not written.
    if (tag === 'svg') return '';
    if (tag === 'br') return '\n';
    if (tag === 'img') return `![${node.getAttribute('alt') ?? ''}](${node.getAttribute('src') ?? ''})`;
    if (tag === 'code') return `\`${collapse(node.textContent ?? '').trim()}\``;

    const children = () => Array.from(node.childNodes).map(inline).join('');

    if (tag === 'a') {
        // getAttribute, not `.href`: the reply is parsed into a detached
        // document, where reading the property would resolve a root-relative
        // `/r/` citation against the parser's base URL and destroy the exact
        // shape absolutizeRecordLinks rewrites.
        const href = node.getAttribute('href') ?? '';
        const label = children().trim();

        if (href === '') return label;
        if (label === '' || label === href) return href;

        return `[${label}](${href})`;
    }

    const wrapper = INLINE_WRAPPERS[tag];

    if (wrapper !== undefined) {
        const body = children();

        if (body.trim() === '') return body;

        // The surrounding spaces stay outside the markers: `a **b** c` reads,
        // `a ** b ** c` does not emphasize anything at all.
        const lead = body.startsWith(' ') ? ' ' : '';
        const tail = body.endsWith(' ') ? ' ' : '';

        return `${lead}${wrapper}${body.trim()}${wrapper}${tail}`;
    }

    return children();
};

const inlineOf = (element) => Array.from(element.childNodes).map(inline).join('').trim();

/** The block-level children of one element, each already serialized. */
const blocksOf = (parent) => {
    const out = [];
    let buffer = '';

    const flush = () => {
        const text = buffer.trim();
        if (text !== '') out.push(text);
        buffer = '';
    };

    for (const child of parent.childNodes) {
        if (child.nodeType === ELEMENT_NODE && BLOCK_TAGS.has(tagOf(child))) {
            flush();
            const text = block(child);
            if (text !== '') out.push(text);
            continue;
        }

        buffer += inline(child);
    }

    flush();

    return out;
};

const list = (element) => {
    const ordered = tagOf(element) === 'ol';
    const start = Number(element.getAttribute('start') ?? 1) || 1;

    return Array.from(element.children)
        .filter((child) => tagOf(child) === 'li')
        .map((item, index) => {
            const marker = ordered ? `${start + index}. ` : '- ';
            const indent = ' '.repeat(marker.length);
            // Single newlines inside an item: a nested list hangs directly off
            // the line above it, and a blank line would end the list.
            const [first = '', ...rest] = blocksOf(item).join('\n').split('\n');

            return [marker + first, ...rest.map((line) => (line === '' ? line : indent + line))].join('\n');
        })
        .join('\n');
};

// A markdown table has no headerless form, so the first row leads whether it
// came from a `thead` or not. Both pipelines only produce tables from markdown
// tables, which always have a header row.
const table = (element) => {
    const rows = Array.from(element.querySelectorAll('tr'))
        .map((row) => Array.from(row.children).map((cell) => inlineOf(cell).replaceAll('|', '\\|')));

    if (rows.length === 0) return '';

    const width = Math.max(...rows.map((cells) => cells.length));
    const line = (cells) => `| ${[...cells, ...Array(width - cells.length).fill('')].join(' | ')} |`;

    return [line(rows[0]), line(Array(width).fill('---')), ...rows.slice(1).map(line)].join('\n');
};

const block = (element) => {
    const tag = tagOf(element);

    if (tag === 'hr') return '---';
    if (tag === 'p') return inlineOf(element);
    if (tag === 'ul' || tag === 'ol') return list(element);
    if (tag === 'table') return table(element);

    if (/^h[1-6]$/.test(tag)) {
        return `${'#'.repeat(Number(tag[1]))} ${inlineOf(element)}`;
    }

    if (tag === 'pre') {
        const code = element.querySelector('code');
        const language = (code?.getAttribute('class') ?? '').match(/language-([\w-]+)/)?.[1] ?? '';

        return `\`\`\`${language}\n${(code ?? element).textContent.replace(/\n+$/, '')}\n\`\`\``;
    }

    if (tag === 'blockquote') {
        return blocksOf(element)
            .join('\n\n')
            .split('\n')
            .map((line) => `> ${line}`.trimEnd())
            .join('\n');
    }

    return blocksOf(element).join('\n\n');
};

/**
 * One rendered reply fragment as structured plain text: headings, list
 * markers, fenced code, pipe tables and link targets all survive; the markup
 * does not.
 */
export const htmlToText = (html) => {
    if (typeof html !== 'string' || html.trim() === '') return '';

    // An inert document: parseFromString runs no script and fetches no
    // subresource, and the fragment reaching here has already been sanitized
    // for the DOM it is painted into.
    const { body } = new DOMParser().parseFromString(html, 'text/html');

    return blocksOf(body).join('\n\n').replace(/\n{3,}/g, '\n\n').trim();
};
