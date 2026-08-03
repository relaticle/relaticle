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
    <x-filament::button
        outlined
        color="gray"
        size="sm"
        icon="heroicon-o-chat-bubble-left-right"
        x-on:click="window.Livewire.dispatch('chat:toggle-panel')"
        x-bind:aria-label="isMac ? 'Ask Relaticle (Cmd+J)' : 'Ask Relaticle (Ctrl+J)'"
        x-bind:title="isMac ? 'Ask Relaticle (Cmd+J)' : 'Ask Relaticle (Ctrl+J)'"
    >
        <span class="hidden sm:inline">Ask Relaticle</span>

        <kbd class="hidden font-mono text-[11px] opacity-60 sm:inline" aria-hidden="true">
            <span x-text="isMac ? '⌘J' : 'Ctrl+J'"></span>
        </kbd>
    </x-filament::button>
</div>
