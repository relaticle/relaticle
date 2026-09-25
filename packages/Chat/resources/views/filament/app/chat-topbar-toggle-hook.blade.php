@php
    $askLabel = __('Ask :name', ['name' => (string) config('chat.assistant_name')]);
    // Server-derived, so the predicate is independent of how the panel is routed.
    // Counting path segments only ever identified the tenant root on a
    // domain-routed install; every path-routed one (the .env.example default)
    // showed this button on the dashboard, next to the composer that does the
    // same thing. Path-only: the dashboard and the current page always share a
    // host, and comparing full URLs would break on a query string.
    $dashboardPath = parse_url(\App\Filament\Pages\Dashboard::getUrl(), PHP_URL_PATH) ?? '/';
@endphp

<div
    x-data="{
        onChatPage: false,
        check() {
            const path = window.location.pathname.replace(/\/+$/, '') || '/';
            const dashboard = @js(rtrim($dashboardPath, '/') ?: '/');
            // Hide on the dashboard (its composer is the same entry point) and
            // on any /chats route (the conversation IS the chat).
            this.onChatPage = path === dashboard || /\/chats(\/|$)/.test(path);
        },
        init() {
            this.check();
            this.navigatedHandler = () => this.check();
            document.addEventListener('livewire:navigated', this.navigatedHandler);
        },
        destroy() {
            document.removeEventListener('livewire:navigated', this.navigatedHandler);
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
        :aria-label="$askLabel"
        :title="$askLabel"
    >
        <span class="hidden sm:inline">{{ $askLabel }}</span>
    </x-filament::button>
</div>
