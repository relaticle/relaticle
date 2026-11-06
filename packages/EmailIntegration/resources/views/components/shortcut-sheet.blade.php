@php
    use Relaticle\EmailIntegration\Enums\EmailShortcut;
    use Relaticle\EmailIntegration\Enums\ShortcutScope;

    $scopes = array_map(fn (ShortcutScope $scope): array => ['scope' => $scope->value, 'label' => $scope->label()], ShortcutScope::cases());
@endphp

{{-- Lists only keys some component on this page handles and the off switch lets fire, so a dead key never appears. --}}
<div
    data-shortcut-sheet
    x-data="{
        open: false,
        returnTo: null,
        shortcut: null,
        scopes: @js($scopes),
        init() {
            this.shortcut = this.$store.shortcuts?.register('email-help', {
                scope: 'global',
                entries: @js(EmailShortcut::forStore()),
                handlers: { help: () => this.show() },
            })
        },
        destroy() {
            this.$store.shortcuts?.unregister('email-help', this.shortcut)
        },
        show() {
            this.returnTo = document.activeElement
            this.open = true
        },
        hide() {
            this.open = false
            this.$nextTick(() => this.returnTo?.focus?.())
        },
        groups() {
            const registrations = this.$store.shortcuts?.registrations ?? []

            return this.scopes
                .map((group) => ({
                    ...group,
                    rows: registrations
                        .filter((registration) => registration.scope === group.scope)
                        .flatMap((registration) => registration.entries)
                        .filter((entry, index, entries) => entries.findIndex((other) => other.action === entry.action) === index)
                        .map((entry) => ({ ...entry, keys: this.$store.shortcuts.liveKeys(entry) }))
                        .filter((entry) => entry.keys.length > 0),
                }))
                .filter((group) => group.rows.length > 0)
        },
        keyLabel(key) {
            return key === 'Escape' ? 'Esc' : key
        },
    }"
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        x-on:click.self="hide()"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4 dark:bg-gray-950/75"
    >
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="shortcut-sheet-heading"
            x-trap.noscroll="open"
            x-on:keydown.escape.prevent.stop="hide()"
            x-on:keydown="if ($event.key === '?') { $event.preventDefault(); hide() }"
            class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        >
            <header class="flex items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-white/10">
                <h2 id="shortcut-sheet-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ __('filament/pages/email-inbox.shortcuts.heading') }}
                </h2>
                <button
                    type="button"
                    x-on:click="hide()"
                    aria-label="{{ __('filament/pages/email-inbox.shortcuts.close') }}"
                    class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:hover:bg-white/10 dark:hover:text-gray-200"
                >
                    <x-heroicon-m-x-mark class="size-4" />
                </button>
            </header>

            <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                <template x-for="group in groups()" x-bind:key="group.scope">
                    <section>
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" x-text="group.label"></h3>
                        <dl class="divide-y divide-gray-100 dark:divide-white/5">
                            <template x-for="row in group.rows" x-bind:key="row.action">
                                <div class="flex items-center justify-between gap-4 py-1.5 text-sm">
                                    <dt class="text-gray-700 dark:text-gray-200" x-text="row.label"></dt>
                                    <dd class="flex shrink-0 gap-1">
                                        <template x-for="key in row.keys" x-bind:key="key">
                                            <kbd
                                                class="min-w-6 rounded-md bg-gray-100 px-1.5 py-0.5 text-center font-mono text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10"
                                                x-text="keyLabel(key)"
                                            ></kbd>
                                        </template>
                                    </dd>
                                </div>
                            </template>
                        </dl>
                    </section>
                </template>
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-5 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                <p x-text="$store.shortcuts?.singleKeys ? @js(__('filament/pages/email-inbox.shortcuts.single_keys_on')) : @js(__('filament/pages/email-inbox.shortcuts.single_keys_off'))"></p>
                <a href="{{ \App\Filament\Pages\EditProfile::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ __('filament/pages/email-inbox.shortcuts.profile_link') }}
                </a>
            </footer>
        </div>
    </div>
</div>
