<div
    x-data="{
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
        aria-label="Ask Relaticle"
        title="Ask Relaticle"
    >
        <span class="hidden sm:inline">Ask Relaticle</span>
    </x-filament::button>
</div>
