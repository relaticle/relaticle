@props([
    'email',
    'pendingAccessRequests',
    'thread',
    'bodies',
    'expanded' => [],
    'row' => null,
    'adjacent' => null,
    'unavailable' => false,
])

@php
    $storedWidth = "(() => { try { return Math.min(70, Math.max(40, Number(localStorage.getItem('relaticle.emailReader.width')) || 55)) } catch { return 55 } })()";
@endphp

@if ($email === null && $unavailable)
    <aside
        wire:key="email-reader-unavailable"
        x-data="{ width: {{ $storedWidth }} }"
        style="--ei-reader-width: 55vw"
        x-bind:style="{ '--ei-reader-width': width + 'vw' }"
        x-init="$nextTick(() => document.getElementById('email-reader-heading')?.focus())"
        role="dialog"
        aria-modal="false"
        aria-labelledby="email-reader-heading"
        x-on:keydown.escape.window="$event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']') || $wire.deselectEmail()"
        class="fi-email-reader-drawer fixed inset-0 z-40 flex flex-col bg-white shadow-xl ring-1 ring-gray-950/10 sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[var(--ei-reader-width)] lg:max-w-[calc(100vw-var(--sidebar-width,0px))] dark:bg-gray-900 dark:ring-white/10"
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
            leaving: null,

            closing: false,

            width: {{ $storedWidth }},

            resizing: false,

            resizeTo(vw, persist = true) {
                this.width = Math.round(Math.min(70, Math.max(40, vw)))

                if (persist) {
                    try {
                        localStorage.setItem('relaticle.emailReader.width', this.width)
                    } catch {}
                }
            },

            leaveReader(then) {
                this.leaving ??= (async () => {
                    try {
                        const dock = $el.querySelector('[data-inline-composer][wire\\:id]')

                        if (dock) {
                            await window.Livewire.find(dock.getAttribute('wire:id')).call('close')
                        }

                        await then()
                    } finally {
                        this.leaving = null
                    }
                })()

                return this.leaving
            },

            async closeReader() {
                if (this.closing) {
                    return
                }

                this.closing = true
                let conversationKey = $el.dataset.conversationKey

                if (this.leaving) {
                    await this.leaving
                    conversationKey = document.querySelector('[data-conversation-key]')?.dataset.conversationKey
                    await $wire.deselectEmail()
                } else {
                    await this.leaveReader(() => $wire.deselectEmail())
                }

                const row = (conversationKey && document.querySelector(`[data-conversation='${CSS.escape(conversationKey)}'] > button`))
                    || document.querySelector('[data-conversation] > button')

                row?.focus()
            },
        }"
        x-init="$nextTick(() => document.getElementById('email-reader-heading')?.focus())"
        wire:key="email-reader-{{ $email->getKey() }}"
        data-conversation-key="{{ $email->conversation_key }}"
        style="--ei-reader-width: 55vw"
        x-bind:style="{ '--ei-reader-width': width + 'vw', ...(closing ? { display: 'none' } : {}) }"
        x-bind:class="resizing && 'select-none'"
        x-on:keydown.escape.window="$event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']') || closeReader()"
        x-on:email-reader-leave.window="$event.preventDefault(); leaveReader($event.detail.then)"
        role="dialog"
        aria-modal="false"
        aria-labelledby="email-reader-heading"
        {{ $attributes->class(['fi-email-reader-drawer fixed inset-0 z-40 flex flex-col bg-white shadow-xl ring-1 ring-gray-950/10 sm:inset-y-0 sm:left-auto sm:right-0 sm:w-[var(--ei-reader-width)] lg:max-w-[calc(100vw-var(--sidebar-width,0px))] dark:bg-gray-900 dark:ring-white/10']) }}
    >
        <div
            role="separator"
            aria-orientation="vertical"
            aria-label="{{ __('filament/pages/email-inbox.drawer.resize') }}"
            aria-valuemin="40"
            aria-valuemax="70"
            x-bind:aria-valuenow="width"
            x-bind:aria-valuetext="@js(__('filament/pages/email-inbox.drawer.resize_value')).replace(':width', width)"
            tabindex="0"
            x-on:pointerdown="resizing = true; $el.setPointerCapture($event.pointerId)"
            x-on:pointermove="resizing && resizeTo((window.innerWidth - $event.clientX) / window.innerWidth * 100, false)"
            x-on:pointerup="resizing = false; resizeTo(width)"
            x-on:pointercancel="resizing = false; resizeTo(width)"
            x-on:keydown.left.prevent="resizeTo(width + 5)"
            x-on:keydown.right.prevent="resizeTo(width - 5)"
            class="absolute inset-y-0 left-0 z-10 hidden w-1.5 -translate-x-1/2 cursor-col-resize touch-none outline-none transition-colors hover:bg-primary-500/40 focus-visible:bg-primary-500 sm:block"
        ></div>

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
                    @if ($adjacent !== null)
                        <div class="flex items-center">
                            @foreach (['previous' => 'ri-arrow-up-s-line', 'next' => 'ri-arrow-down-s-line'] as $direction => $icon)
                                <button
                                    type="button"
                                    data-reader-action="{{ $direction }}"
                                    x-on:click="leaveReader(() => $wire.openAdjacentConversation('{{ $direction }}'))"
                                    @disabled($adjacent[$direction] === null)
                                    aria-label="{{ __("filament/pages/email-inbox.drawer.{$direction}") }}"
                                    class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                >
                                    <x-filament::icon :icon="$icon" class="h-4 w-4" />
                                </button>
                            @endforeach
                        </div>
                    @endif

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
            <x-email-integration::reader-thread :thread="$thread" :bodies="$bodies" :expanded="$expanded" :reply-target="$replyTarget" :email="$email" />
        </div>
    </aside>
@endif
