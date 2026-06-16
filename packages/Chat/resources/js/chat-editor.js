import { Editor } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import HardBreak from '@tiptap/extension-hard-break';
import Placeholder from '@tiptap/extension-placeholder';
import Mention from '@tiptap/extension-mention';
import { createMentionSuggestion } from './chat-mention-suggestion';

// Editors live outside Alpine's reactive proxy. Wrapping a TipTap editor in a
// Proxy breaks ProseMirror's identity checks ("Applying a mismatched
// transaction") because internal doc/state references are compared by identity
// when transactions are applied.
const editorByEl = new WeakMap();

function deepClone(value) {
    if (typeof structuredClone === 'function') {
        return structuredClone(value);
    }
    return JSON.parse(JSON.stringify(value));
}

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

export function chatEditor({ initialDocument, placeholder, onSubmit, onChange, autofocus } = {}) {
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
                    };
                },
                parseHTML() {
                    return [{ tag: 'span[data-mention-id]' }];
                },
                renderHTML({ node, HTMLAttributes }) {
                    return ['span', {
                        'data-mention-id': node.attrs.id,
                        'data-mention-type': node.attrs.type,
                        'class': 'inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200',
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
                        suggestion: createMentionSuggestion(),
                    }),
                ],
                // ProseMirror requires `block+` content; pass an empty paragraph rather
                // than an empty content array so the Placeholder extension has a node to
                // attach to and the editor reports a non-zero selection range.
                content: hasContent(initialDocument)
                    ? deepClone(initialDocument)
                    : { type: 'doc', content: [{ type: 'paragraph' }] },
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

        setDocument(doc) {
            const editor = editorByEl.get(this.editorEl);
            if (! editor) return;
            const content = hasContent(doc)
                ? deepClone(doc)
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
