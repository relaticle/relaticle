import { Editor } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import HardBreak from '@tiptap/extension-hard-break';
import Placeholder from '@tiptap/extension-placeholder';
import Mention from '@tiptap/extension-mention';
import { createMentionSuggestion } from './chat-mention-suggestion';
import { MENTION_CHIP_CLASS } from './chat/mention-chip';

// Editors live outside Alpine's reactive proxy. Wrapping a TipTap editor in a
// Proxy breaks ProseMirror's identity checks ("Applying a mismatched
// transaction") because internal doc/state references are compared by identity
// when transactions are applied.
const editorByEl = new WeakMap();

function hasContent(doc) {
    if (! doc || ! Array.isArray(doc.content) || doc.content.length === 0) return false;
    // A doc with a single empty paragraph is treated as no content.
    if (doc.content.length === 1) {
        const node = doc.content[0];
        if (node?.type === 'paragraph' && (! Array.isArray(node.content) || node.content.length === 0)) {
            return false;
        }
    }
    return true;
}

export function chatEditor({ placeholder, onSubmit, onChange, onArrowUp, autofocus, mentionTexts } = {}) {
    return {
        editorEl: null,
        // Reactive mirror of the editor's plain text. Alpine bindings depending
        // on editor state (e.g. :disabled="isEmpty() || text.length > 5000")
        // must read this, not call getText(), because the editor lives outside
        // Alpine's reactive proxy.
        text: '',

        init() {
            this.editorEl = this.$refs.editor;

            const ChatMention = Mention.extend({
                addAttributes() {
                    return {
                        id: { default: null },
                        type: { default: null },
                        label: { default: null },
                        // Server-resolved record URL captured from the mention picker.
                        // Persisted in the document JSON so a just-sent message can render
                        // a clickable chip before the authoritative server mentions arrive.
                        url: { default: null },
                    };
                },
                parseHTML() {
                    return [{ tag: 'span[data-mention-id]' }];
                },
                renderHTML({ node, HTMLAttributes }) {
                    return ['span', {
                        'data-mention-id': node.attrs.id,
                        'data-mention-type': node.attrs.type,
                        'class': MENTION_CHIP_CLASS,
                        ...HTMLAttributes,
                    }, '@' + (node.attrs.label ?? '')];
                },
            });

            const editor = new Editor({
                element: this.editorEl,
                extensions: [
                    Document,
                    Paragraph,
                    Text,
                    HardBreak.configure({ keepMarks: false }),
                    Placeholder.configure({ placeholder: placeholder ?? 'Ask anything…' }),
                    ChatMention.configure({
                        HTMLAttributes: { class: 'mention' },
                        suggestion: createMentionSuggestion({ texts: mentionTexts }),
                    }),
                ],
                // ProseMirror requires `block+` content; pass an empty paragraph rather
                // than an empty content array so the Placeholder extension has a node to
                // attach to and the editor reports a non-zero selection range.
                content: { type: 'doc', content: [{ type: 'paragraph' }] },
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm max-w-none focus:outline-none min-h-[64px] max-h-[40vh] overflow-y-auto px-4 pt-3 pb-2 text-sm leading-6',
                    },
                    handleKeyDown: (view, event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            // ProseMirror runs editorProps.handleKeyDown BEFORE plugin
                            // handlers. If we submit here unconditionally, the mention
                            // suggestion plugin never gets to intercept Enter — typing
                            // "Hello @" + Enter would submit the message instead of
                            // letting the user finish picking a mention. Defer to plugin
                            // handlers when a mention suggestion is active.
                            for (const plugin of view.state.plugins) {
                                const state = plugin.getState?.(view.state);
                                if (state && state.active === true) {
                                    return false;
                                }
                            }
                            event.preventDefault();
                            onSubmit?.();
                            return true;
                        }
                        // ArrowUp-to-edit: only when the doc is empty (a single empty
                        // paragraph has nothing meaningful for the browser's default
                        // ArrowUp to do anyway, so intercepting unconditionally here
                        // is safe (a non-empty composer keeps normal cursor movement).
                        if (event.key === 'ArrowUp' && editor.isEmpty) {
                            event.preventDefault();
                            onArrowUp?.();
                            return true;
                        }
                        return false;
                    },
                },
                onUpdate: ({ editor }) => {
                    const text = editor.getText();
                    this.text = text;
                    onChange?.({ document: editor.getJSON(), text });
                },
            });

            editorByEl.set(this.editorEl, editor);
            this.text = editor.getText();

            if (autofocus) {
                this.$nextTick(() => editorByEl.get(this.editorEl)?.commands.focus('end'));
            }
        },

        destroy() {
            const editor = editorByEl.get(this.editorEl);
            editor?.destroy();
            editorByEl.delete(this.editorEl);
        },

        getDocument() {
            return editorByEl.get(this.editorEl)?.getJSON() ?? { type: 'doc', content: [] };
        },

        getText() {
            return (editorByEl.get(this.editorEl)?.getText() ?? '').trim();
        },

        setText(text) {
            const editor = editorByEl.get(this.editorEl);
            if (! editor) return;
            editor.commands.setContent({
                type: 'doc',
                content: [
                    text === ''
                        ? { type: 'paragraph' }
                        : { type: 'paragraph', content: [{ type: 'text', text }] },
                ],
            });
            this.text = text;
        },

        // Drops text at the caret and leaves the caret after it, so a dictated
        // sentence lands wherever the user was typing and they keep editing.
        // The content is passed as an explicit text node rather than a string:
        // TipTap parses a string as HTML, and a transcription is arbitrary user
        // speech that may contain angle brackets.
        insertText(text) {
            const editor = editorByEl.get(this.editorEl);
            if (! editor || ! text) return;
            editor.chain().focus().insertContent({ type: 'text', text }).run();
            this.text = editor.getText();
        },

        setDocument(doc) {
            const editor = editorByEl.get(this.editorEl);
            if (! editor) return;
            const content = hasContent(doc)
                ? structuredClone(doc)
                : { type: 'doc', content: [{ type: 'paragraph' }] };
            editor.commands.setContent(content);
            this.text = editor.getText();
        },

        clear() {
            const editor = editorByEl.get(this.editorEl);
            if (! editor) return;
            // Use setContent({...emptyParagraph}) rather than clearContent(): the
            // latter is silently dropped when invoked re-entrantly from inside
            // ProseMirror's handleKeyDown (which is the path Enter -> onSubmit
            // -> sendMessage -> clear takes), so the editor visibly kept its
            // text. setContent doesn't share that re-entrancy bug.
            editor.commands.setContent({ type: 'doc', content: [{ type: 'paragraph' }] });
            this.text = '';
        },

        focus() {
            editorByEl.get(this.editorEl)?.commands.focus('end');
        },

        isEmpty() {
            return editorByEl.get(this.editorEl)?.isEmpty ?? true;
        },
    };
}
