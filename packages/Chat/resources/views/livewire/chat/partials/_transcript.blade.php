{{-- Messages --}}
@php
    // Keep streamed and persisted Markdown styling identical.
    $proseClasses = 'prose prose-sm dark:prose-invert w-full max-w-none [overflow-wrap:anywhere] px-1 py-1 text-gray-900 dark:text-gray-100 [&>*:first-child]:mt-0 [&>*:last-child]:mb-0 prose-headings:text-gray-900 dark:prose-headings:text-white prose-table:my-2 prose-table:border-collapse prose-thead:border-b prose-thead:border-gray-300 dark:prose-thead:border-gray-600 prose-th:px-2 prose-th:py-2 prose-th:text-left prose-td:border-t prose-td:border-gray-100 prose-td:px-2 prose-td:py-2 dark:prose-td:border-gray-700 prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[length:var(--text-micro)] prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-gray-900 prose-pre:rounded-lg prose-pre:bg-gray-900 prose-pre:text-gray-100 first:prose-headings:mt-0';
@endphp
<div
    x-ref="messages"
    role="log"
    {{-- Streaming mutates the DOM per token; announcing only once the turn
         settles spares screen-reader users hundreds of partial readouts. --}}
    :aria-live="isStreaming ? 'off' : 'polite'"
    aria-relevant="additions text"
    aria-atomic="false"
    x-on:scroll.passive="trackScrollPosition()"
    {{-- Keep sr-only descendants inside the transcript scroll container.
         Otherwise, their static positions can inflate the page height. --}}
    class="relative flex-1 overflow-y-auto px-4 py-6"
>
    <template x-if="messages.length === 0 && !isStreaming">
        <div class="flex h-full items-center justify-center px-6">
            <div class="mx-auto max-w-md text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-900/20 dark:text-primary-400">
                    <x-heroicon-o-sparkles class="h-6 w-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ __('What do you need to know?') }}
                </h3>
                {{-- The side panel keeps its empty state to the greeting: the record
                     it is bound to already frames what to ask, so the starter chips
                     are noise there. --}}
                @if (($context ?? 'conversation') === 'side-panel')
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Ask about this record, or anything else in your CRM.') }}
                    </p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ __("Ask about a deal, a contact, or what's overdue. Try one of these:") }}
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <template x-for="starter in starterPrompts" :key="starter.label">
                            @include('chat::livewire.chat.partials._prompt-chip', ['item' => 'starter', 'click' => 'input = starter.prompt; localEditor()?.setText(starter.prompt); $nextTick(() => sendMessage())'])
                        </template>
                    </div>
                @endif
            </div>
        </div>
    </template>

    {{-- Sticky date pill: shows the calendar day the user has scrolled past
         (updated via IntersectionObserver over the inline separators below).
         The wrapper is `sticky` + zero-height so toggling the pill never
         shifts scroll content; the pill itself overflows above/below that
         0px box. Purely a supplementary visual aid: the inline separators
         already carry the same information in reading order, so it stays
         out of the accessibility tree. --}}
    <div class="sticky top-2 z-20 flex h-0 justify-center" aria-hidden="true">
        <span
            x-show="stickyDateLabel"
            x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-150"
            x-transition:enter-start="motion-safe:opacity-0"
            x-transition:enter-end="motion-safe:opacity-100"
            x-transition:leave="motion-safe:transition motion-safe:ease-in motion-safe:duration-100"
            x-transition:leave-start="motion-safe:opacity-100"
            x-transition:leave-end="motion-safe:opacity-0"
            x-text="stickyDateLabel"
            style="display: none;"
            class="rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 shadow-lg backdrop-blur-sm dark:text-gray-200"
        ></span>
    </div>

    {{-- Top sentinel: intersecting drives loadEarlier() automatically (see
         initLoadEarlierObserver() in transcript.js). Kept outside the
         max-w-3xl/space-y-6 list below so it is a single persistent node,
         never recreated by the x-for over messages, and never disturbs that
         list's own spacing. --}}
    <div x-ref="topSentinel" aria-hidden="true" class="h-px"></div>

    <div class="mx-auto max-w-3xl space-y-5">
        <template x-if="hasMoreMessages">
            <div class="flex justify-center py-2">
                <button
                    type="button"
                    x-on:click="loadEarlier()"
                    :disabled="loadingEarlier"
                    class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-wait dark:text-gray-300 dark:hover:bg-white/5"
                >
                    <x-heroicon-o-arrow-path x-show="loadingEarlier" x-cloak class="h-3 w-3 motion-safe:animate-spin" aria-hidden="true" />
                    <span x-text="loadingEarlier ? @js(__('Loading earlier messages…')) : @js(__('Load earlier messages'))"></span>
                </button>
            </div>
        </template>

        {{-- Keyed by stable clientKey, NOT index: splices (regenerate/edit),
             prepends (load earlier) and pops (continuation revert) must not
             re-bind DOM nodes across different logical messages. --}}
        <template x-for="(msg, index) in messages" :key="msg.clientKey || ('i-' + index)">
            {{-- The negative inline margin cancels the padding, so the search
                 highlight below gets breathing room around the bubble without
                 the row shifting when it comes and goes. --}}
            <div
                class="group/message -mx-3 rounded-xl px-3 motion-safe:transition-colors"
                {{-- Jump target for the in-conversation search overlay (see
                     revealMessage() in transcript.js). Absent on an optimistic
                     bubble, which has no server id to search by yet. --}}
                :data-message-id="msg.id || null"
                {{-- Tightens the gap AFTER this message when the NEXT one groups
                     with it (space-y-6 on the parent sets each item's own
                     trailing margin, so the message being pulled closer to its
                     successor is the one whose margin must shrink). --}}
                :class="[
                    (index + 1 < messages.length && decorations(index + 1).grouped) ? 'mb-1' : '',
                    (msg.id && msg.id === highlightedMessageId) ? 'bg-primary-50 dark:bg-primary-500/10' : '',
                ]"
            >
                {{-- Day separator: rendered above this message when its calendar
                     day differs from the previous message's. --}}
                <template x-if="decorations(index).daySeparator">
                    <div data-day-separator class="mb-4 flex justify-center">
                        <span
                            x-text="decorations(index).daySeparator"
                            class="rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300"
                        ></span>
                    </div>
                </template>

                {{-- User message --}}
                <template x-if="msg.role === 'user'">
                    <div class="flex justify-end" data-user-bubble :data-grouped="decorations(index).grouped" :data-send-state="msg.sendState ?? 'sent'">
                        <div class="flex max-w-[85%] flex-col items-end gap-1">
                            <template x-if="!msg.editing">
                                {{-- Soft brand tint, not solid primary-600: a wall of
                                     saturated bubbles overpowered the transcript; the
                                     tint keeps who-said-what without shouting. --}}
                                <div
                                    :title="msg.created_at ? new Date(msg.created_at).toLocaleString() : ''"
                                    class="[overflow-wrap:anywhere] break-words rounded-2xl rounded-br-md bg-primary-50 px-4 py-2.5 text-sm text-gray-900 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-500/15 dark:text-gray-100 dark:ring-primary-400/15"
                                >
                                    <span x-html="renderMessageContent(msg)" class="whitespace-pre-wrap"></span>
                                </div>
                            </template>

                            {{-- Grounding trace: the record this message was bound to. --}}
                            <template x-if="msg.page_context && !msg.editing">
                                <a
                                    :href="msg.page_context.url || '#'"
                                    :class="msg.page_context.url ? '' : 'pointer-events-none'"
                                    class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-primary-700 ring-1 ring-primary-600/20 transition hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30 dark:hover:bg-primary-500/20"
                                >
                                    <x-heroicon-m-at-symbol class="h-3 w-3 shrink-0" aria-hidden="true" />
                                    <span class="truncate" x-text="msg.page_context.label"></span>
                                </a>
                            </template>

                            {{-- Telegram-style send-state glyph: only rendered for a bubble sent THIS
                                 session. Reloaded (persisted) messages carry no sendState and stay
                                 silent rather than showing a permanent checkmark on every past message. --}}
                            <template x-if="msg.sendState && !msg.editing">
                                <div
                                    class="flex items-center gap-1 px-1 text-[length:var(--text-micro)]"
                                    :class="msg.sendState === 'failed' ? 'text-red-500 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'"
                                >
                                    <template x-if="msg.sendState === 'sending'">
                                        <span role="status" class="inline-flex">
                                            <x-heroicon-o-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Sending') }}</span>
                                        </span>
                                    </template>
                                    <template x-if="msg.sendState === 'sent'">
                                        <span role="status" class="inline-flex">
                                            <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span class="sr-only">{{ __('Sent') }}</span>
                                        </span>
                                    </template>
                                    <template x-if="msg.sendState === 'failed'">
                                        <span class="inline-flex items-center gap-1" role="alert">
                                            <x-heroicon-o-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span>{{ __('Not sent') }}</span>
                                            <button
                                                type="button"
                                                data-resend-button
                                                x-on:click="resendMessage(msg)"
                                                :disabled="isStreaming"
                                                class="font-medium text-red-600 underline decoration-red-300 underline-offset-2 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                {{ __('Resend') }}
                                            </button>
                                        </span>
                                    </template>
                                </div>
                            </template>

                            <template x-if="msg.editing">
                                <div class="w-full min-w-[16rem] rounded-2xl rounded-br-md bg-primary-50 p-2 ring-1 ring-inset ring-primary-600/10 dark:bg-primary-500/15 dark:ring-primary-400/15">
                                    <textarea
                                        :id="'chat-edit-' + index"
                                        x-ref="editArea"
                                        x-model="msg.editText"
                                        @input="autosize($event.target)"
                                        @keydown.escape.prevent="cancelEdit(msg)"
                                        @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); saveEdit(msg, index) }"
                                        rows="1"
                                        maxlength="5000"
                                        aria-label="{{ __('Edit message') }}"
                                        class="block min-h-[28px] w-full resize-none rounded-xl border-0 bg-white px-3 py-2 text-sm leading-6 text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-primary-500/40 dark:bg-gray-900 dark:text-gray-100"
                                        style="max-height: 200px;"
                                    ></textarea>
                                    <div class="mt-2 flex items-center justify-between gap-2 px-1">
                                        <span
                                            class="text-[length:var(--text-micro)]"
                                            :class="{
                                                'text-gray-500 dark:text-gray-400': (msg.editText || '').length <= 4900,
                                                'text-amber-600 dark:text-amber-400': (msg.editText || '').length > 4900 && (msg.editText || '').length <= 5000,
                                                'text-red-600 dark:text-red-400': (msg.editText || '').length > 5000,
                                            }"
                                            x-text="(msg.editText || '').length > 4000 ? `${(msg.editText || '').length.toLocaleString()} / 5,000` : ''"
                                        ></span>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                x-on:click="cancelEdit(msg)"
                                                class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-primary-600/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-500 dark:text-gray-300 dark:hover:bg-white/10"
                                            >
                                                {{ __('Cancel') }}
                                            </button>
                                            <button
                                                type="button"
                                                x-on:click="saveEdit(msg, index)"
                                                :disabled="!(msg.editText || '').trim() || (msg.editText || '').length > 5000 || isStreaming || rateLimit !== null"
                                                class="rounded-lg bg-primary-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:bg-primary-600/50"
                                            >
                                                {{ __('Save & resend') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!msg.editing && !isStreaming">
                                <div class="flex items-center gap-1 px-1 opacity-0 transition group-hover/message:opacity-100 focus-within:opacity-100">
                                    <button
                                        type="button"
                                        x-on:click="copyMessage(msg)"
                                        :aria-label="copiedKey === msg.clientKey ? @js(__('Copied')) : @js(__('Copy message'))"
                                        :title="copiedKey === msg.clientKey ? @js(__('Copied')) : @js(__('Copy message'))"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                    >
                                        <template x-if="copiedKey === msg.clientKey">
                                            <x-heroicon-s-check class="h-3.5 w-3.5 text-green-600 dark:text-green-400" aria-hidden="true" />
                                        </template>
                                        <template x-if="copiedKey !== msg.clientKey">
                                            <x-heroicon-o-document-duplicate class="h-3.5 w-3.5" aria-hidden="true" />
                                        </template>
                                    </button>
                                    <button
                                        type="button"
                                        data-edit-button
                                        x-on:click="(canEdit(index) && rateLimit === null) && startEdit(msg, index)"
                                        :disabled="!canEdit(index) || rateLimit !== null"
                                        :title="editButtonLabel(index)"
                                        :aria-label="editButtonLabel(index)"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Assistant message: flat on the canvas, no bubble. Long-form
                     markdown (headings, tables, code) reads better unconstrained,
                     which is the layout ChatGPT/Claude/Attio converged on; the
                     user bubble opposite keeps who-said-what scanning. --}}
                <template x-if="msg.role === 'assistant' && (msg.rendered || msg.content || msg.streamError || (index === messages.length - 1 && isStreaming && currentToolStatus))">
                    <div class="flex flex-col items-start" data-assistant-bubble :data-grouped="decorations(index).grouped">
                        <div class="flex w-full justify-start" x-show="msg.content || !msg.rendered || displayBlocks(msg).length > 0 || (index === messages.length - 1 && isStreaming && currentToolStatus)">
                            <div
                                :title="msg.created_at ? @js(__('Completed :time')).replace(':time', new Date(msg.created_at).toLocaleString()) : ''"
                                class="w-full min-w-0"
                            >
                                {{-- Rendered reply: text and display blocks interleaved in
                                     reading order. messageSegments() honors {{ '{{block:N}}' }}
                                     placement markers and appends unplaced blocks below,
                                     which is also the no-marker default. --}}
                                <template x-if="msg.rendered">
                                    <div>
                                        <template x-for="(seg, segIdx) in messageSegments(msg)" :key="segIdx">
                                            <div class="w-full">
                                                <template x-if="seg.type === 'html'">
                                                    <div
                                                        x-html="seg.html"
                                                        class="{{ $proseClasses }}"
                                                    ></div>
                                                </template>
                                                {{-- Single-element x-for: binds the loop var name
                                                     `block`, which the included partials expect. --}}
                                                <template x-if="seg.type === 'block' && seg.block.block === 'records_table'">
                                                    <template x-for="block in [seg.block]" :key="segIdx + '-table'">
                                                        <div class="my-3 w-full">
                                                            @include('chat::livewire.chat.partials._block-records-table')
                                                        </div>
                                                    </template>
                                                </template>
                                                <template x-if="seg.type === 'block' && seg.block.block === 'record_card'">
                                                    <template x-for="block in [seg.block]" :key="segIdx + '-card'">
                                                        <div class="my-3 w-full">
                                                            @include('chat::livewire.chat.partials._block-record-card')
                                                        </div>
                                                    </template>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!msg.rendered">
                                    <div class="w-full">
                                        {{-- Hide incomplete Markdown while tokens arrive.
                                             Match the persisted reply styling to prevent reflow. --}}
                                        <template x-if="msg.content">
                                            <div x-html="streamingHtml(msg)" class="{{ $proseClasses }}"></div>
                                        </template>
                                        <template x-if="index === messages.length - 1 && isStreaming && currentToolStatus">
                                            <div data-chat-loading-indicator class="flex items-center gap-2 px-1 py-1 text-xs" role="status" :class="{ 'mt-2': msg.content }">
                                                <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
                                                <span data-chat-loading-label x-text="pendingLabel"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <template x-if="msg.streamError">
                            <div class="mt-2 flex max-w-[85%] items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 dark:border-amber-700 dark:bg-amber-900/20" role="alert">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                                <span class="flex-1 text-xs text-amber-800 [overflow-wrap:anywhere] dark:text-amber-200" x-text="msg.streamError"></span>
                                <button
                                    type="button"
                                    data-retry-button
                                    x-show="msg.retryable && !isStreaming && !rateLimit"
                                    x-on:click="retryTurn(msg)"
                                    class="rounded-md bg-amber-600 px-2 py-1 text-xs font-medium text-white transition hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                                >
                                    {{ __('Retry') }}
                                </button>
                            </div>
                        </template>

                        <template x-if="msg.rendered && Array.isArray(msg.follow_ups) && msg.follow_ups.length > 0">
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template x-for="chip in msg.follow_ups" :key="chip.prompt">
                                    @include('chat::livewire.chat.partials._prompt-chip', ['item' => 'chip', 'click' => 'input = chip.prompt; localEditor()?.setText(chip.prompt); $nextTick(() => sendMessage())'])
                                </template>
                            </div>
                        </template>

                        <template x-if="msg.rendered && msg.content">
                            <div class="mt-1 flex items-center gap-1 px-1 opacity-0 transition group-hover/message:opacity-100 focus-within:opacity-100">
                                <button
                                    type="button"
                                    x-on:click="copyMessage(msg)"
                                    :aria-label="copiedKey === msg.clientKey ? @js(__('Copied')) : @js(__('Copy message'))"
                                    :title="copiedKey === msg.clientKey ? @js(__('Copied')) : @js(__('Copy message'))"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                >
                                    <template x-if="copiedKey === msg.clientKey">
                                        <x-heroicon-s-check class="h-3.5 w-3.5 text-green-600 dark:text-green-400" aria-hidden="true" />
                                    </template>
                                    <template x-if="copiedKey !== msg.clientKey">
                                        <x-heroicon-o-document-duplicate class="h-3.5 w-3.5" aria-hidden="true" />
                                    </template>
                                </button>
                                {{-- Icon-only like its copy/thumbs siblings (ChatGPT's action-row
                                     density); the tooltip carries the label. --}}
                                <button
                                    type="button"
                                    data-regenerate-button
                                    x-show="!isStreaming"
                                    x-on:click="regenerateMessage(index)"
                                    :disabled="!canRegenerate(index) || rateLimit !== null"
                                    :aria-label="regenerateButtonLabel(index)"
                                    :title="regenerateButtonLabel(index)"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200 dark:disabled:hover:bg-transparent dark:disabled:hover:text-gray-400"
                                >
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                </button>
                                <template x-if="msg.id">
                                    <span class="flex items-center gap-0.5">
                                        <button
                                            type="button"
                                            x-on:click="rateMessage(msg, 'up')"
                                            :aria-pressed="msg.feedback?.rating === 'up'"
                                            aria-label="{{ __('Good response') }}"
                                            title="{{ __('Good response') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800"
                                            :class="msg.feedback?.rating === 'up' ? 'text-green-600 dark:text-green-400' : 'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                        >
                                            <template x-if="msg.feedback?.rating === 'up'">
                                                <x-heroicon-s-hand-thumb-up class="h-3.5 w-3.5" aria-hidden="true" />
                                            </template>
                                            <template x-if="msg.feedback?.rating !== 'up'">
                                                <x-heroicon-o-hand-thumb-up class="h-3.5 w-3.5" aria-hidden="true" />
                                            </template>
                                        </button>
                                        <button
                                            type="button"
                                            x-on:click="rateMessage(msg, 'down')"
                                            :aria-pressed="msg.feedback?.rating === 'down'"
                                            aria-label="{{ __('Bad response') }}"
                                            title="{{ __('Bad response') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800"
                                            :class="msg.feedback?.rating === 'down' ? 'text-red-600 dark:text-red-400' : 'text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                        >
                                            <template x-if="msg.feedback?.rating === 'down'">
                                                <x-heroicon-s-hand-thumb-down class="h-3.5 w-3.5" aria-hidden="true" />
                                            </template>
                                            <template x-if="msg.feedback?.rating !== 'down'">
                                                <x-heroicon-o-hand-thumb-down class="h-3.5 w-3.5" aria-hidden="true" />
                                            </template>
                                        </button>
                                    </span>
                                </template>
                            </div>
                        </template>

                        {{-- Thumbs-down detail funnel: category chips + optional comment --}}
                        <template x-if="msg.feedbackPanelOpen">
                            <div class="mt-2 w-full max-w-[85%] rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('What went wrong?') }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <template x-for="cat in feedbackCategories" :key="cat.value">
                                        <button
                                            type="button"
                                            x-on:click="msg.feedbackCategory = (msg.feedbackCategory === cat.value ? null : cat.value)"
                                            x-text="cat.label"
                                            :aria-pressed="msg.feedbackCategory === cat.value"
                                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                            :class="msg.feedbackCategory === cat.value
                                                ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300'
                                                : 'border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] text-gray-600 hover:border-gray-300 dark:text-gray-300'"
                                        ></button>
                                    </template>
                                </div>
                                <textarea
                                    x-model="msg.feedbackComment"
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="{{ __('Tell us more (optional)') }}"
                                    aria-label="{{ __('Feedback details') }}"
                                    class="mt-2 block w-full resize-none rounded-md border-[var(--surface-input-border)] bg-[var(--surface-input-bg)] px-2.5 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:text-gray-100"
                                ></textarea>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        x-on:click="msg.feedbackPanelOpen = false"
                                        class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-500 transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:bg-gray-700"
                                    >
                                        {{ __('Skip') }}
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="submitFeedbackDetail(msg)"
                                        class="rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                                    >
                                        {{ __('Submit') }}
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Paywall card for credits_exhausted state --}}
                <template x-if="msg.paywall">
                    <div class="flex justify-start">
                        <div class="flex max-w-[85%] flex-col gap-3 rounded-2xl rounded-bl-md border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-900/50 dark:bg-amber-900/10">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-sparkles class="h-5 w-5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                                <h4 class="text-sm font-semibold text-amber-900 dark:text-amber-100" x-text="msg.paywall.heading"></h4>
                            </div>
                            <p class="text-sm text-amber-800 dark:text-amber-200" x-text="msg.paywall.body"></p>
                            <div class="flex gap-2">
                                <a :href="msg.paywall.upgrade_url" class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-amber-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400">
                                    {{ __('Add credits') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Action cards: resolved cards stay inline as the audit trail.
                     A still-pending batch that already has some resolved items
                     ALSO renders inline as a compact progress card (the editor
                     for the unresolved items lives docked at the composer; see
                     input area). A fully-unresolved proposal is dock-only. --}}
                <template x-if="msg.pending_actions && msg.pending_actions.length > 0">
                    <div class="mt-3 space-y-3">
                        <template x-for="action in msg.pending_actions" :key="action.pending_action_id">
                            <div class="space-y-2">
                                <template x-if="action.status !== 'pending' || (action.itemResults && Object.keys(action.itemResults).length > 0)">
                                    @include('chat::livewire.chat.partials._proposal-card')
                                </template>

                                {{-- Agent outcome summary once the proposal is finalized. Reload-safe:
                                     derived from the persisted action by proposalOutcome(), not a stored message. --}}
                                {{-- Flat status line, matching the flattened assistant text
                                     it follows; the sparkles icon marks it as the agent's note. --}}
                                <template x-if="action.status !== 'pending' && proposalOutcome(action)">
                                    <div class="flex justify-start">
                                        <div class="inline-flex items-start gap-1.5 px-1 py-1 text-sm text-gray-600 [overflow-wrap:anywhere] dark:text-gray-300">
                                            <x-heroicon-o-sparkles class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary-500" aria-hidden="true" />
                                            <span x-text="proposalOutcome(action)"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        {{-- Pre-token streaming indicator: flat shimmer label where the
             assistant's text will land --}}
        <template x-if="isStreaming && !currentToolStatus && (messages.length === 0 || messages[messages.length-1].role !== 'assistant' || !messages[messages.length-1].content)">
            <div class="flex justify-start" aria-label="{{ __('Assistant is thinking') }}" role="status">
                <div data-chat-loading-indicator class="flex items-center gap-2 px-1 py-2 text-sm text-gray-600 dark:text-gray-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
                    <span data-chat-loading-label x-text="pendingLabel"></span>
                </div>
            </div>
        </template>
    </div>

    {{-- Jump-to-latest pill: sticky to the bottom of THIS scrollable transcript
         viewport, mirroring the sticky date pill above it (same zero-height-wrapper
         trick). Anchoring here rather than to the page means it can never land on
         top of the docked proposal card: the composer/dock sit entirely outside
         this scrolling box, whatever height they take, so there is nothing in this
         container for the pill to overlap. --}}
    <div class="sticky bottom-2 z-20 flex h-0 justify-center">
        <template x-if="hasUnseenBelow">
            <button
                type="button"
                x-on:click="scrollToBottom(true)"
                class="flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 shadow-lg backdrop-blur-sm transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                x-transition:enter="motion-safe:transition motion-safe:ease-out motion-safe:duration-150"
                x-transition:enter-start="motion-safe:opacity-0 motion-safe:translate-y-1"
                x-transition:enter-end="motion-safe:opacity-100 motion-safe:translate-y-0"
            >
                <x-heroicon-o-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('New messages') }}
            </button>
        </template>
    </div>
</div>
