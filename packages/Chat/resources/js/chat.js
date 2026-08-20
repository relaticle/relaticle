import { marked } from 'marked'
import DOMPurify from 'dompurify'

marked.setOptions({ breaks: true, gfm: true })

window.renderMarkdown = (text) => {
    if (!text) return ''
    return DOMPurify.sanitize(marked.parse(text))
}

import '../css/chat-editor.css';
import { chatEditor } from './chat-editor';
import { transcriptModule } from './chat/transcript';
import { sendModule } from './chat/send';
import { streamModule } from './chat/stream';
import { registerBlock, blockTemplate } from './chat/blocks';
import { modelPickerModule } from './chat/model-picker';

const registerChatEditor = () => {
    if (!window.Alpine) {
        return false;
    }
    window.Alpine.data('chatEditor', chatEditor);
    return true;
};

if (!registerChatEditor()) {
    document.addEventListener('alpine:init', registerChatEditor);
}

// chatInterface's Alpine.data() factory lives inline in
// chat-interface.blade.php (it needs Blade's @js() to inline per-request
// server data). These module factories are exposed here so that inline
// script can compose them without a bundler import.
//
// registerBlock/blockTemplate are plain functions, not module factories:
// exposed alongside so the transcript partial (also inline, unbundled) can
// call window.ChatModules.blockTemplate() directly.
window.ChatModules = { transcriptModule, sendModule, streamModule, registerBlock, blockTemplate, modelPickerModule };
