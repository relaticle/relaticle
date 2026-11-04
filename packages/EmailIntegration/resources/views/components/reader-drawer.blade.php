@props([
    'email',
    'pendingAccessRequests',
    'thread',
    'bodies',
    'expanded' => [],
    'row' => null,
    'unavailable' => false,
])

@if ($email === null && $unavailable)
    <aside
        wire:key="email-reader-unavailable"
        role="dialog"
        aria-modal="false"
        aria-labelledby="email-reader-heading"
        x-on:keydown.escape.window="$event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']') || $wire.deselectEmail()"
        class="fi-email-reader-drawer fixed inset-0 z-40 flex flex-col bg-white shadow-xl ring-1 ring-gray-950/10 sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[55vw] lg:max-w-[calc(100vw-var(--sidebar-width,0px))] dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="flex items-start gap-3 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
            <p id="email-reader-heading" tabindex="-1" class="min-w-0 flex-1 text-sm text-gray-600 outline-none dark:text-gray-300">
                {{ __('filament/pages/email-inbox.drawer.not_available') }}
            </p>
            <button
                wire:click="deselectEmail"
                type="button"
                aria-label="{{ __('filament/emails/composer.actions.close') }}"
                class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
            >
                <x-heroicon-m-x-mark class="h-4 w-4" />
            </button>
        </div>
    </aside>
@elseif ($email !== null)
    @php
        $authUser = auth()->user();
        $newest = $thread->last() ?? $email;
        $replyTarget = $thread->last(fn (\Relaticle\EmailIntegration\Models\Email $message): bool => $authUser->can('viewBody', $message));
        $subject = html_entity_decode((string) $newest->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    @endphp

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
        <header class="shrink-0 border-b border-gray-200 px-4 py-3 sm:px-6 dark:border-gray-700">
            <div class="flex items-start gap-3">
                <h2 id="email-reader-heading" tabindex="-1" class="min-w-0 flex-1 break-words text-base font-semibold leading-snug text-gray-900 outline-none dark:text-white">
                    @if ($authUser->can('viewSubject', $newest))
                        {{ $subject ?: __('filament/pages/email-inbox.subject.none') }}
                    @else
                        <span class="text-sm font-normal italic text-gray-400 dark:text-gray-500">{{ __('filament/pages/email-inbox.subject.hidden') }}</span>
                    @endif
                </h2>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($replyTarget !== null)
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="ri-reply-line"
                            data-reader-action="reply"
                            x-on:click="$dispatch('composer:reply', { emailId: '{{ $replyTarget->getKey() }}', mode: 'reply' })"
                        >
                            {{ __('filament/pages/email-inbox.drawer.reply') }}
                        </x-filament::button>
                    @endif

                    <x-email-integration::detail-action-bar :email="$email" />

                    <button
                        x-on:click="closeReader()"
                        type="button"
                        aria-label="{{ __('filament/emails/composer.actions.close') }}"
                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                    >
                        <x-heroicon-m-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>

            @if ($email->user_id !== $authUser->getKey())
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament/pages/email-inbox.drawer.source', ['name' => $email->user?->name ?? __('filament/pages/email-inbox.pending_access.unknown_user')]) }}
                </p>
            @endif

            @if ($row !== null && $row->records !== [])
                <div class="mt-2 flex flex-wrap items-center gap-1">
                    @foreach ($row->records as $record)
                        <x-email-integration::record-chip :record="$record" class="inline-flex" />
                    @endforeach
                </div>
            @endif
        </header>

        <x-email-integration::reader-access-bar :pending-access-requests="$pendingAccessRequests" />

        <div class="min-h-0 flex-1 overflow-y-auto motion-safe:scroll-smooth" data-email-scroll-region>
            <x-email-integration::reader-thread :thread="$thread" :bodies="$bodies" :expanded="$expanded" :reply-target="$replyTarget" />
        </div>
    </aside>
@endif
