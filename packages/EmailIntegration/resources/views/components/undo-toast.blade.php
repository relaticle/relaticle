@php
    $verbs = collect(['markDone', 'reopen', 'markRead', 'markUnread'])->mapWithKeys(fn (string $verb): array => [$verb => [
        'one' => trans_choice("filament/pages/email-inbox.triage.toast.{$verb}", 1),
        'other' => trans_choice("filament/pages/email-inbox.triage.toast.{$verb}", 2, ['count' => ':count']),
    ]]);
@endphp

{{-- The newest action owns the toast, so a second one replaces the first and only its Undo remains. --}}
<div
    wire:ignore
    data-undo-toast
    x-data="{
        verbs: @js($verbs),
        skippedText: @js(__('filament/pages/email-inbox.triage.toast.skipped')),
        undoHint: @js(__('filament/pages/email-inbox.triage.toast.undo_hint')),
        undoKeyHint: @js(__('filament/pages/email-inbox.triage.toast.undo_key_hint')),
        inverse: { markDone: 'reopen', reopen: 'markDone', markRead: 'markUnread', markUnread: 'markRead' },
        verb: null,
        keys: [],
        text: '',
        announcement: '',
        visible: false,
        timer: null,
        shortcut: null,
        init() {
            this.shortcut = this.$store.shortcuts?.register('email-undo', {
                scope: 'global',
                entries: @js(\Relaticle\EmailIntegration\Enums\EmailShortcut::forStore()),
                handlers: { undo: () => this.undo() },
                isActive: () => this.visible && this.verb !== null,
            })
        },
        destroy() {
            clearTimeout(this.timer)
            this.$store.shortcuts?.unregister('email-undo', this.shortcut)
        },
        show({ verb, outcome }) {
            const applied = outcome.applied.length
            const parts = applied > 0 ? [(applied === 1 ? this.verbs[verb].one : this.verbs[verb].other).replace(':count', applied)] : []

            outcome.skipped.forEach((skip) => parts.push(this.skippedText.replace(':count', skip.count).replace(':reason', skip.label)))

            if (parts.length === 0) {
                return
            }

            this.verb = applied > 0 ? verb : null
            this.keys = outcome.applied
            this.text = parts.join(' ')
            this.announcement = this.verb ? `${this.text} ${this.$store.shortcuts?.keysFor('undo') ? this.undoKeyHint : this.undoHint}` : this.text
            this.visible = true
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.dismiss(), 5000)
        },
        dismiss() {
            clearTimeout(this.timer)
            this.visible = false
            this.announcement = ''
        },
        undo() {
            const detail = { verb: this.inverse[this.verb], keys: this.keys, undoing: true }

            this.dismiss()
            window.dispatchEvent(new CustomEvent('email-triage', { detail }))
        },
    }"
    x-on:email-triage-done.window="show($event.detail)"
>
    <div aria-live="polite" class="sr-only" x-text="announcement"></div>

    <div
        x-show="visible"
        x-cloak
        x-transition.opacity
        class="fixed inset-x-4 bottom-4 z-50 mx-auto flex max-w-md items-center gap-3 rounded-xl bg-gray-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-gray-950/10 sm:inset-x-auto sm:left-1/2 sm:-translate-x-1/2 dark:bg-gray-800 dark:ring-white/10"
    >
        <p class="min-w-0 flex-1" x-text="text"></p>

        <button
            type="button"
            x-show="verb !== null"
            x-on:click="undo()"
            data-undo-action
            x-bind:title="$store.shortcuts?.hint('undo', @js(__('filament/pages/email-inbox.triage.toast.undo')))"
            x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('undo')"
            class="shrink-0 rounded-md px-2 py-1 font-semibold text-primary-300 transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
        >
            {{ __('filament/pages/email-inbox.triage.toast.undo') }}
        </button>

        <button
            type="button"
            x-on:click="dismiss()"
            aria-label="{{ __('filament/pages/email-inbox.triage.toast.dismiss') }}"
            class="shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
        >
            <x-heroicon-m-x-mark class="size-4" />
        </button>
    </div>
</div>
