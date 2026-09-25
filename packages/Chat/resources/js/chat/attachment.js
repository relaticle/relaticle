// One CSV or TXT file per message. Mounted as its own island inside the shared
// composer bar (like voiceRecorder), so every surface gets it from the one
// partial. It uploads first and hands the sender an id; nothing is sent here.
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

export function chatAttachment({ uploadUrl, deleteUrlTemplate, context, maxBytes, texts }) {
    return {
        attachment: null,
        uploading: false,
        attachError: null,
        dragging: false,
        _root: null,
        _composer: null,
        _onConsumed: null,
        _onDragOver: null,
        _onDragLeave: null,
        _onDrop: null,

        init() {
            this._root = this.$el;
            this._composer = this._root.closest('[data-chat-composer]');

            this._onConsumed = (event) => {
                if (event.detail?.context === context) this.attachment = null;
            };
            window.addEventListener('chat:attachment-consumed', this._onConsumed);

            if (! this._composer) return;
            this._onDragOver = (event) => { event.preventDefault(); this.setDragging(true); };
            this._onDragLeave = (event) => { if (! this._composer.contains(event.relatedTarget)) this.setDragging(false); };
            this._onDrop = (event) => {
                event.preventDefault();
                this.setDragging(false);
                const file = event.dataTransfer?.files?.[0];
                if (file) this.upload(file);
            };
            this._composer.addEventListener('dragover', this._onDragOver);
            this._composer.addEventListener('dragleave', this._onDragLeave);
            this._composer.addEventListener('drop', this._onDrop);
        },

        destroy() {
            window.removeEventListener('chat:attachment-consumed', this._onConsumed);
            this._composer?.removeEventListener('dragover', this._onDragOver);
            this._composer?.removeEventListener('dragleave', this._onDragLeave);
            this._composer?.removeEventListener('drop', this._onDrop);
        },

        setDragging(on) {
            this.dragging = on;
            this._composer?.classList.toggle('ring-2', on);
            this._composer?.classList.toggle('ring-primary-400', on);
        },

        pick() {
            this.$refs.fileInput?.click();
        },

        onFileChosen(event) {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (file) this.upload(file);
        },

        async upload(file) {
            if (this.uploading) return;
            this.attachError = null;

            if (! /\.(csv|txt)$/i.test(file.name)) {
                this.attachError = texts.wrongType;
                return;
            }
            if (file.size > maxBytes) {
                this.attachError = texts.tooLarge;
                return;
            }

            const body = new FormData();
            body.append('file', file);
            const conversationId = this.conversationScope()?.conversationId;
            if (conversationId) body.append('conversation_id', conversationId);

            this.uploading = true;
            try {
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (! res.ok) {
                    this.attachError = json?.errors?.file?.[0] || json?.message || texts.failed;
                    return;
                }
                this.attachment = { id: json.id, name: json.name, row_count: json.row_count, conversation_id: json.conversation_id };
                this.publish();
            } catch {
                this.attachError = texts.failed;
            } finally {
                this.uploading = false;
            }
        },

        // The row and file go with the chip; a failed delete still clears the
        // composer, and the server purges what it never received back.
        async remove() {
            const removed = this.attachment;
            this.attachment = null;
            this.publish();

            if (! removed?.id) return;
            try {
                await fetch(deleteUrlTemplate.replace('__ID__', removed.id), {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                });
            } catch {
                // Left for chat:purge-unsent-attachments.
            }
        },

        publish() {
            window.dispatchEvent(new CustomEvent('chat:attachment-changed', {
                detail: { context, attachment: this.attachment },
            }));
        },

        // The full-page chat and the side panel expose their conversation id on
        // the chatInterface root; the dashboard has none, so the upload opens
        // the conversation and the first send joins it.
        conversationScope() {
            const host = this._root?.closest('[x-data^="chatInterface"]');
            return host && window.Alpine ? window.Alpine.$data(host) : null;
        },
    };
}
