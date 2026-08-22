// Push-to-talk dictation for the composer. Registered as an Alpine component
// (see chat.js) and mounted as its own island inside the composer bar, so the
// full-page chat, the side panel and the dashboard all get it from the one
// shared partial without any of their Alpine roots knowing about it.
//
// It only ever puts text in the editor. Nothing here sends a message: the user
// reviews what came back and presses send like any other message.

// Ordered by preference. Chrome/Edge/Firefox take the first, Safari the last.
// Every entry must be covered by the server's mimetypes allowlist. A type the
// browser can record but the endpoint rejects would be a silent 422, so a
// browser supporting none of these is refused up front instead.
const RECORDING_TYPES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];

const MAX_RECORDING_MS = 120_000;

export function voiceRecorder({ transcribeUrl, unsupportedText, deniedText, failedText, silentText }) {
    return {
        recording: false,
        transcribing: false,
        voiceError: null,

        // Kept off the reactive surface deliberately: a MediaRecorder wrapped
        // in Alpine's proxy loses its native `this` on the event callbacks.
        _recorder: null,
        _stopTimer: null,
        _root: null,

        init() {
            this._root = this.$el;
        },

        // wire:navigate tears this component down without firing onstop, so a
        // navigation mid-recording would leave the tab's recording indicator
        // lit and the mic hot. Stopping here runs onstop, which releases the
        // tracks.
        destroy() {
            this._recorder?.stop();
        },

        // The composer bar is shared, so the editor is found by walking up from
        // this island rather than by a context name. This lands on the same
        // wrapper chatInterface.localEditor() and the dashboard's own helper
        // resolve, scoped to this instance by DOM ancestry instead of a selector.
        editorScope() {
            const wrapper = this._root?.closest('[x-data*="chatEditor"]');
            return wrapper && window.Alpine ? window.Alpine.$data(wrapper) : null;
        },

        async toggleRecording() {
            if (this.recording) {
                this._recorder?.stop();
                return;
            }

            if (this.transcribing) return;

            this.voiceError = null;

            const mimeType = RECORDING_TYPES.find(
                (type) => window.MediaRecorder?.isTypeSupported?.(type),
            );

            if (! mimeType || ! navigator.mediaDevices?.getUserMedia) {
                this.voiceError = unsupportedText;
                return;
            }

            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                this.voiceError = deniedText;
                return;
            }

            const chunks = [];
            const recorder = new MediaRecorder(stream, { mimeType });

            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) chunks.push(event.data);
            };

            recorder.onstop = () => {
                clearTimeout(this._stopTimer);
                // Without this the browser keeps the tab's recording indicator
                // lit (and the mic hot) until the page is closed.
                stream.getTracks().forEach((track) => track.stop());
                this._recorder = null;
                this.recording = false;

                const blob = new Blob(chunks, { type: recorder.mimeType });
                if (blob.size > 0) this.transcribeBlob(blob);
            };

            this._recorder = recorder;
            this.recording = true;
            recorder.start();

            this._stopTimer = setTimeout(() => this._recorder?.stop(), MAX_RECORDING_MS);
        },

        // Split out from onstop so the whole upload-and-insert path can be
        // driven with a fixture blob in a browser that has no microphone.
        async transcribeBlob(blob) {
            this.transcribing = true;
            this.voiceError = null;

            const body = new FormData();
            body.append('audio', blob, 'recording');

            try {
                const response = await fetch(transcribeUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body,
                });

                if (! response.ok) {
                    this.voiceError = failedText;
                    return;
                }

                const text = (await response.json())?.text?.trim() ?? '';

                if (text === '') {
                    this.voiceError = silentText;
                    return;
                }

                this.editorScope()?.insertText(text);
            } catch {
                this.voiceError = failedText;
            } finally {
                this.transcribing = false;
            }
        },
    };
}
