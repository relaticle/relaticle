import { marked } from 'marked'
import DOMPurify from 'dompurify'

marked.setOptions({ breaks: true, gfm: true })

window.renderMarkdown = (text) => {
    if (!text) return ''
    return DOMPurify.sanitize(marked.parse(text))
}

import '../css/chat-editor.css';
import { chatEditor } from './chat-editor';

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
