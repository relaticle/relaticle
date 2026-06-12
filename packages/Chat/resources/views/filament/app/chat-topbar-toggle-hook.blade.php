<div
    x-data="{
        isMac: navigator.platform.toLowerCase().includes('mac'),
        onChatPage: false,
        check() {
            const p = window.location.pathname;
            const segments = p.split('/').filter(Boolean);
            // Hide on the tenant root (dashboard, single-segment path) and on any /chats route.
            this.onChatPage = segments.length === 1 || /\/chats(\/|$)/.test(p);
        },
        init() {
            this.check();
            document.addEventListener('livewire:navigated', () => this.check());
        }
    }"
    x-show="!onChatPage"
    x-cloak
    class="me-2"
>
    <button
        type="button"
        @click="window.Livewire.dispatch('chat:toggle-panel')"
        :aria-label="isMac ? 'Ask Relaticle (Cmd+J)' : 'Ask Relaticle (Ctrl+J)'"
        :title="isMac ? 'Ask Relaticle (Cmd+J)' : 'Ask Relaticle (Ctrl+J)'"
        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm font-medium text-gray-600 shadow-sm transition hover:bg-gray-50 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
    >
        <x-heroicon-o-chat-bubble-left-right class="h-4 w-4" aria-hidden="true" />
        <span class="hidden sm:inline">Ask Relaticle</span>
        <kbd class="hidden font-mono text-[11px] text-gray-400 dark:text-gray-500 sm:inline" aria-hidden="true">
            <span x-text="isMac ? '⌘J' : 'Ctrl+J'"></span>
        </kbd>
    </button>
</div>
