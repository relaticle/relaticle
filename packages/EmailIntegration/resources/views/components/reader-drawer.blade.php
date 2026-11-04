@props([
    'email',
    'pendingAccessRequests',
])

@if ($email !== null)
    {{-- Mounted per email with no x-show, like the overlay: Alpine visibility state does
         not survive Livewire morphing this element. --}}
    <aside
        x-data="{
            closing: false,

            async closeReader() {
                if (this.closing) {
                    return
                }

                this.closing = true

                const dock = $el.querySelector('[data-inline-composer][wire\\:id]')

                if (dock) {
                    await window.Livewire.find(dock.getAttribute('wire:id')).call('close')
                }

                $wire.deselectEmail()
            },
        }"
        wire:key="email-reader-{{ $email->getKey() }}"
        x-bind:style="closing ? { display: 'none' } : {}"
        x-on:keydown.escape.window="$event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']') || closeReader()"
        role="dialog"
        aria-modal="false"
        aria-labelledby="email-reader-heading"
        {{ $attributes->class(['fi-email-reader-drawer fixed inset-0 z-40 flex flex-col bg-white shadow-xl ring-1 ring-gray-950/10 sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[55vw] lg:max-w-[calc(100vw-var(--sidebar-width,0px))] dark:bg-gray-900 dark:ring-white/10']) }}
    >
        <div class="flex h-11 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 dark:border-gray-700">
            <span id="email-reader-heading" class="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
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
            <x-email-integration::reader-access-bar :pending-access-requests="$pendingAccessRequests" />

            <x-email-integration::email-view :record="$email" />
        </div>
    </aside>
@endif
