import { marked } from 'marked'
import DOMPurify from 'dompurify'

marked.setOptions({ breaks: true, gfm: true })

// Record chips, client half. The server half is RecordChipRenderer in
// packages/Chat/src/Support/RecordChipRenderer.php and the two must emit the
// same markup character for character: a reply is rendered here the moment it
// finishes streaming and rendered there after a reload, in the same bubble.
// The URL shape, the icon set and the element order below are all copied from
// that class; change one side and change the other in the same commit.
const RECORD_CHIP_URL = /^\/r\/([a-z_]+)\/[\w-]+$/

const RECORD_CHIP_ICONS = {
    company: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    people: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    opportunity: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    task: 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
    note: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
}

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
    + `<path stroke-linecap="round" stroke-linejoin="round" d="${RECORD_CHIP_ICONS[type]}"></path>`
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

window.renderMarkdown = (text) => {
    if (!text) return ''
    return applyRecordChips(DOMPurify.sanitize(marked.parse(text)))
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
