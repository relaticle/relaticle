@props([
    'email',
    'pendingAccessRequests',
    'layer' => 'z-40',
])

@if ($email !== null)
    {{-- Animated in CSS rather than with x-show. Alpine visibility state does not
         survive Livewire morphing this element. The state flips to shown while a
         stale inline `display: none` stays behind, leaving an invisible overlay.
         A mount animation has no state to fall out of sync. --}}
    <div
        x-data="{
            /**
             * Save any reply in progress before the reader goes away. Closing
             * removes the docked composer along with the reader, so an event asking
             * it to save its draft would arrive after it no longer exists, and the
             * draft has to be persisted first, and awaited.
             */
            async closeReader() {
                const dock = document.querySelector('[data-inline-composer][wire\\:id]')

                if (dock) {
                    await window.Livewire.find(dock.getAttribute('wire:id')).call('close')
                }

                $wire.deselectEmail()
            },
        }"
        wire:key="email-reader"
        x-on:keydown.escape.window="closeReader()"
        {{ $attributes->class(['fixed inset-0 flex items-center justify-center p-4 sm:p-6', $layer]) }}
    >
        <div
            x-on:click="closeReader()"
            class="fi-email-reader-backdrop absolute inset-0 bg-gray-950/50"
        ></div>

        <div class="fi-email-reader-panel relative flex h-[85vh] w-full max-w-5xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex h-11 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 dark:border-gray-700">
                <span class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <x-heroicon-m-envelope class="h-4 w-4 text-gray-400" />
                    {{ __('filament/pages/email-inbox.reader.heading') }}
                </span>
                <button
                    x-on:click="closeReader()"
                    type="button"
                    aria-label="{{ __('filament/emails/composer.actions.close') }}"
                    class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                >
                    <x-heroicon-m-x-mark class="h-4 w-4" />
                </button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col">
                <x-emails.reader-access-bar :pending-access-requests="$pendingAccessRequests" />

                <x-emails.email-view :record="$email" />
            </div>
        </div>
    </div>
@endif
