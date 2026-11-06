@props(['userId', 'workspaceId'])

{{-- One refetch per burst of signals. A hidden tab only collects them, and a tab hidden past a
     minute refetches on return, covering a dropped socket and changes that send no signal. --}}
<div
    wire:ignore
    hidden
    data-live-conversations
    x-data="{
        event: '.email.conversation.changed',
        debounceMs: 750,
        staleAfterMs: 60000,
        pending: new Set(),
        timer: null,
        hiddenAt: null,
        channel: null,
        handler: null,
        resumeHandler: null,
        init() {
            this.handler = (signal) => this.collect(signal)
            this.resumeHandler = () => this.resume()
            document.addEventListener('visibilitychange', this.resumeHandler)
            window.addEventListener('focus', this.resumeHandler)

            if (window.Echo === undefined) {
                return
            }

            this.channel = window.Echo.private(@js('App.Models.User.'.$userId))
            this.channel.listen(this.event, this.handler)
        },
        destroy() {
            clearTimeout(this.timer)
            document.removeEventListener('visibilitychange', this.resumeHandler)
            window.removeEventListener('focus', this.resumeHandler)
            {{-- Never Echo.leave(): Filament's notifications share this channel. --}}
            this.channel?.stopListening(this.event, this.handler)
        },
        collect(signal) {
            if (signal?.workspace_id !== @js((string) $workspaceId) || ! signal.conversation_key) {
                return
            }

            this.pending.add(signal.conversation_key)

            if (! document.hidden) {
                this.schedule()
            }
        },
        schedule() {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.flush(), this.debounceMs)
        },
        flush() {
            clearTimeout(this.timer)
            this.timer = null
            this.pending.clear()
            $wire.refreshConversations()
        },
        resume() {
            if (document.hidden) {
                this.hiddenAt ??= Date.now()

                return
            }

            const wasStale = this.hiddenAt !== null && Date.now() - this.hiddenAt > this.staleAfterMs
            this.hiddenAt = null

            if (wasStale) {
                this.flush()
            } else if (this.pending.size > 0) {
                this.schedule()
            }
        },
    }"
></div>
