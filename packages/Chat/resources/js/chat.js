import { marked } from 'marked'
import DOMPurify from 'dompurify'

marked.setOptions({ breaks: true, gfm: true })

// Record chips, client half. The server half is RecordChipRenderer in
// packages/Chat/src/Support/RecordChipRenderer.php and the two must emit the
// same markup character for character: a reply is rendered here the moment it
// finishes streaming and rendered there after a reload, in the same bubble.
// The URL shape and the element order below are copied from that class; change
// one side and change the other in the same commit. The icon set is NOT copied:
// it arrives from the server (see RECORD_CHIP_ICONS below).
const RECORD_CHIP_URL = /^\/r\/([a-z_]+)\/[\w-]+$/

// The icon set has exactly one definition: App\Enums\CrmEntity. ChatServiceProvider
// emits it into the page as window.RECORD_CHIP_ICONS, so adding a record type
// server-side reaches this half with no second copy to keep in step.
const RECORD_CHIP_ICONS = window.RECORD_CHIP_ICONS ?? {}

// A chip is a single-line pill, so a line break inside the label would split it
// in two. The pipelines also spell a break differently (`<br />` plus a newline
// server-side, `<br>` from marked here), so both collapse to one space. Mirrors
// RecordChipRenderer::flattenLabel().
const flattenChipLabel = (label) => label
    .replace(/<br\s*\/?>/g, '\n')
    .replace(/[\r\n]+/g, ' ')

// The chip icon on its own, for the block templates: a `records_table` cell and
// a `record_card` heading are chips too, but they are built by Alpine from
// escaped data (`x-text`), never by interpolating a label into an HTML string
// the way the markdown sweep below has to. Only the path data crosses over.
const recordChipIcon = (type) =>
    (Object.hasOwn(RECORD_CHIP_ICONS, type) ? RECORD_CHIP_ICONS[type] : '')

const recordChipHtml = (type, href, label) =>
    `<a class="chat-chip" data-record-type="${type}" href="${href}">`
    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">'
    + `<path stroke-linecap="round" stroke-linejoin="round" d="${recordChipIcon(type)}"></path>`
    + '</svg>'
    + `<span class="chat-chip-label">${flattenChipLabel(label)}</span>`
    + '</a>'

// Runs AFTER DOMPurify, so the icon markup injected here is ours and never the
// model's. The anchor is rebuilt rather than mutated: setting attributes on the
// parsed one would serialize `href` first and diverge from the server's order.
// No wire:navigate: `/r/` is a server redirect, not a Livewire page.
const applyRecordChips = (html) => {
    if (!html.includes('/r/')) return html

    const container = document.createElement('div')
    container.innerHTML = html

    let chipped = false

    container.querySelectorAll('a[href^="/r/"]').forEach((anchor) => {
        const href = anchor.getAttribute('href') ?? ''
        const match = RECORD_CHIP_URL.exec(href)

        // hasOwn, not a truthiness read: `__proto__` and `constructor` both
        // satisfy the `[a-z_]+` type grammar above and would otherwise resolve
        // through Object.prototype, chipping a link the server leaves plain.
        if (!match || !Object.hasOwn(RECORD_CHIP_ICONS, match[1])) return

        anchor.outerHTML = recordChipHtml(match[1], href, anchor.innerHTML)
        chipped = true
    })

    // Untouched markup is returned as it came in rather than re-serialized:
    // a reply with no citations must render exactly as it did before.
    return chipped ? container.innerHTML : html
}

// Keep this normalization aligned with MarkdownRenderer.
// Blank lines isolate block markers before parsing.
// Null record links fall back to plain text.
const normalizeChatMarkdown = (text) => text
    .replace(/^[ \t]*(\{\{block:\d+\}\})[ \t]*$/gm, '\n$1\n')
    .replace(/\[([^\]]+)\]\(null\)/g, '$1')

// Keep character-identical with MarkdownRenderer::wrapTables().
const wrapTables = (html) => {
    if (!html.includes('<table>')) return html
    return html
        .replaceAll('<table>', '<div class="chat-md-table overflow-x-auto" tabindex="0" role="region"><table>')
        .replaceAll('</table>', '</table></div>')
}

// The server renders the same markdown with CommonMark's `html_input => 'strip'`,
// so raw HTML in a reply must not survive here either. DOMPurify's default profile
// is far wider than that: it keeps <form>, <input>, <button> and <style>, which is
// enough to paint a working credential-phishing form inside a trusted surface that
// then vanishes on reload. This allowlist is exactly what MarkdownRenderer can emit
// (CommonMark core + TableExtension + the record chip), and nothing else.
const MARKDOWN_ALLOWED_TAGS = [
    'p', 'br', 'hr', 'blockquote', 'pre', 'code',
    'em', 'strong', 'a', 'img',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'ul', 'ol', 'li',
    'table', 'thead', 'tbody', 'tr', 'th', 'td',
    'div', 'span', 'svg', 'path',
]

const MARKDOWN_ALLOWED_ATTR = [
    'href', 'title', 'src', 'alt', 'align', 'class',
    'data-record-type', 'tabindex', 'role', 'aria-hidden',
    'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'd',
]

const sanitizeMarkdown = (html) => DOMPurify.sanitize(html, {
    ALLOWED_TAGS: MARKDOWN_ALLOWED_TAGS,
    ALLOWED_ATTR: MARKDOWN_ALLOWED_ATTR,
    FORBID_TAGS: ['form', 'input', 'button', 'select', 'textarea', 'style', 'video', 'audio', 'iframe', 'object', 'embed'],
    FORBID_ATTR: ['style', 'action', 'formaction', 'srcset', 'ping'],
})

window.renderMarkdown = (text) => {
    if (!text) return ''
    // Chips are applied before the final sanitize, so the serialize/re-parse round
    // trip DOMPurify warns about cannot reintroduce anything: whatever comes out of
    // applyRecordChips is sanitized once more before it reaches the DOM.
    return wrapTables(sanitizeMarkdown(applyRecordChips(sanitizeMarkdown(marked.parse(normalizeChatMarkdown(text))))))
}

import '../css/chat-editor.css';
import { chatEditor } from './chat-editor';
import { transcriptModule } from './chat/transcript';
import { sendModule } from './chat/send';
import { streamModule } from './chat/stream';
import { isKnownBlock } from './chat/blocks';
import { modelPickerModule } from './chat/model-picker';
import { voiceRecorder } from './chat/voice';

const registerChatComponents = () => {
    if (!window.Alpine) {
        return false;
    }
    window.Alpine.data('chatEditor', chatEditor);
    window.Alpine.data('voiceRecorder', voiceRecorder);
    return true;
};

if (!registerChatComponents()) {
    document.addEventListener('alpine:init', registerChatComponents);
}

// chatInterface's Alpine.data() factory lives inline in
// chat-interface.blade.php (it needs Blade's @js() to inline per-request
// server data). These module factories are exposed here so that inline
// script can compose them without a bundler import.
//
// isKnownBlock/recordChipIcon are plain functions, not module factories:
// exposed alongside so the transcript partials (also inline, unbundled) can
// call window.ChatModules.isKnownBlock() directly.
window.ChatModules = { transcriptModule, sendModule, streamModule, isKnownBlock, recordChipIcon, modelPickerModule };
