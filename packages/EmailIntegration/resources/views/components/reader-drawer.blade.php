@props([
    'email',
    'pendingAccessRequests',
    'thread',
    'bodies',
    'expanded' => [],
    'row' => null,
    'adjacent' => null,
    'unavailable' => false,
    'triage' => false,
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
        data-email-reader
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
                data-reader-action="close"
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
        data-last-inbound="{{ $row?->lastInboundAt?->utc()->format('Y-m-d\TH:i:s\Z') }}"
        @if ($replyTarget !== null) data-reply-target="{{ $replyTarget->getKey() }}" @endif
        style="--ei-reader-width: 55vw"
        x-bind:style="{ '--ei-reader-width': width + 'vw', ...(closing ? { display: 'none' } : {}) }"
        x-bind:class="resizing && 'select-none'"
        x-on:keydown.escape.window="$event.defaultPrevented || document.querySelector('.fi-modal-open, .fi-dropdown-panel[style*=\'display: block\']') || closeReader()"
        x-on:email-reader-leave.window="$event.preventDefault(); leaveReader($event.detail.then)"
        role="dialog"
        aria-modal="false"
        data-email-reader
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
            <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                <h2 id="email-reader-heading" tabindex="-1" class="min-w-48 flex-1 break-words text-base font-semibold leading-snug text-gray-900 outline-none dark:text-white">
                    @if ($authUser->can('viewSubject', $newest))
                        {{ $subject ?: __('filament/pages/email-inbox.subject.none') }}
                    @else
                        <span class="text-sm font-normal italic text-gray-400 dark:text-gray-500">{{ __('filament/pages/email-inbox.subject.hidden') }}</span>
                    @endif
                </h2>

                <div class="ms-auto flex shrink-0 items-center gap-2">
                    @if ($adjacent !== null)
                        <div class="flex items-center">
                            @foreach (['previous' => 'ri-arrow-up-s-line', 'next' => 'ri-arrow-down-s-line'] as $direction => $icon)
                                <button
                                    type="button"
                                    data-reader-action="{{ $direction }}"
                                    x-on:click="leaveReader(() => $wire.openAdjacentConversation('{{ $direction }}'))"
                                    @disabled($adjacent[$direction] === null)
                                    aria-label="{{ __("filament/pages/email-inbox.drawer.{$direction}") }}"
                                    x-bind:title="$store.shortcuts?.hint('{{ $direction }}', @js(__("filament/pages/email-inbox.drawer.{$direction}")))"
                                    x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('{{ $direction }}')"
                                    class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:pointer-events-none disabled:opacity-40 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                >
                                    <x-filament::icon :icon="$icon" class="h-4 w-4" />
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if ($triage && filled($email->conversation_key))
                        @php
                            $triageKeys = [$email->conversation_key];
                            $triageSeen = [$email->conversation_key => $row?->lastInboundAt?->utc()->format('Y-m-d\TH:i:s\Z')];
                            $nextEmailId = ($adjacent['next'] ?? null)?->viewerCopyEmailId;
                        @endphp

                        @if ($row?->isDone)
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="ri-inbox-unarchive-line"
                                data-reader-action="reopen"
                                aria-label="{{ __('filament/pages/email-inbox.triage.actions.reopen') }}"
                                x-bind:title="$store.shortcuts?.hint('done', {{ Js::from(__('filament/pages/email-inbox.triage.actions.reopen')) }})"
                                x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('done')"
                                x-on:click="window.dispatchEvent(new CustomEvent('email-triage', { detail: { verb: 'reopen', keys: {{ Js::from($triageKeys) }}, seen: {{ Js::from($triageSeen) }} } }))"
                            >
                                <span class="hidden sm:inline">{{ __('filament/pages/email-inbox.triage.actions.reopen') }}</span>
                            </x-filament::button>
                        @else
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="ri-check-line"
                                data-reader-action="done"
                                aria-label="{{ __('filament/pages/email-inbox.triage.actions.done') }}"
                                x-bind:title="$store.shortcuts?.hint('done', {{ Js::from(__('filament/pages/email-inbox.triage.actions.done')) }})"
                                x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('done')"
                                x-on:click="window.dispatchEvent(new CustomEvent('email-triage', { detail: {
                                    verb: 'markDone',
                                    keys: {{ Js::from($triageKeys) }},
                                    seen: {{ Js::from($triageSeen) }},
                                    then: () => {{ Js::from($nextEmailId) }} ? leaveReader(() => $wire.selectEmail({{ Js::from($nextEmailId) }})) : closeReader(),
                                } }))"
                            >
                                <span class="hidden sm:inline">{{ __('filament/pages/email-inbox.triage.actions.done') }}</span>
                            </x-filament::button>
                        @endif

                        <button
                            type="button"
                            data-reader-action="mark-unread"
                            x-on:click="window.dispatchEvent(new CustomEvent('email-triage', { detail: { verb: 'markUnread', keys: @js($triageKeys), then: () => closeReader() } }))"
                            title="{{ __('filament/pages/email-inbox.triage.actions.mark_unread') }}"
                            x-bind:title="$store.shortcuts?.hint('markUnread', @js(__('filament/pages/email-inbox.triage.actions.mark_unread')))"
                            x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('markUnread')"
                            aria-label="{{ __('filament/pages/email-inbox.triage.actions.mark_unread') }}"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                        >
                            <x-filament::icon icon="ri-mail-unread-line" class="h-4 w-4" />
                        </button>
                    @endif

                    @if ($replyTarget !== null)
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="ri-reply-line"
                            data-reader-action="reply"
                            x-on:click="$dispatch('composer:reply', { emailId: '{{ $replyTarget->getKey() }}', mode: 'reply' })"
                            x-bind:title="$store.shortcuts?.hint('reply', {{ Js::from(__('filament/pages/email-inbox.drawer.reply')) }})"
                            x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('reply')"
                        >
                            {{ __('filament/pages/email-inbox.drawer.reply') }}
                        </x-filament::button>
                    @endif

                    <x-email-integration::detail-action-bar :email="$email" />

                    <button
                        x-on:click="closeReader()"
                        data-reader-action="close"
                        x-bind:title="$store.shortcuts?.hint('close', @js(__('filament/emails/composer.actions.close')))"
                        x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('close')"
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

            @if ($row !== null)
                <div class="mt-2 flex flex-wrap items-center gap-1" data-reader-links>
                    @foreach ($row->records as $index => $record)
                        <x-email-integration::record-chip
                            :record="$record"
                            :unlink-email-id="$email->getKey()"
                            :class="$index === 0 ? 'inline-flex' : 'hidden sm:inline-flex'"
                            wire:key="reader-link-{{ $email->getKey() }}-{{ $record['type'] }}-{{ $record['id'] }}"
                        />
                    @endforeach

                    @if (count($row->records) > 1)
                        <span class="text-xs font-medium text-gray-400 sm:hidden dark:text-gray-500">
                            {{ __('filament/pages/email-inbox.conversation_row.more_records', ['count' => count($row->records) - 1]) }}
                        </span>
                    @endif

                    <button
                        type="button"
                        data-reader-action="link"
                        wire:click="mountAction('linkRecord', {{ Js::from(['emailId' => $email->getKey()]) }})"
                        x-bind:title="$store.shortcuts?.hint('link', @js(__('filament/pages/email-inbox.drawer.links.add')))"
                        x-bind:aria-keyshortcuts="$store.shortcuts?.keysFor('link')"
                        class="inline-flex items-center gap-0.5 rounded-md px-1.5 py-1 text-xs font-medium leading-4 text-gray-500 ring-1 ring-inset ring-gray-200 outline-none hover:bg-gray-50 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-gray-200"
                    >
                        <x-filament::icon icon="ri-add-line" class="size-3.5" aria-hidden="true" />
                        {{ __('filament/pages/email-inbox.drawer.links.add') }}
                    </button>
                </div>
            @endif
        </header>

        <x-email-integration::reader-access-bar :pending-access-requests="$pendingAccessRequests" />

        <div class="min-h-0 flex-1 overflow-y-auto motion-safe:scroll-smooth" data-email-scroll-region>
            <x-email-integration::reader-thread :thread="$thread" :bodies="$bodies" :expanded="$expanded" :reply-target="$replyTarget" :email="$email" />
        </div>
    </aside>
@endif
