{{-- Messages --}}
<div
    x-ref="messages"
    role="log"
    {{-- Streaming mutates the DOM per token; announcing only once the turn
         settles spares screen-reader users hundreds of partial readouts. --}}
    :aria-live="isStreaming ? 'off' : 'polite'"
    aria-relevant="additions text"
    aria-atomic="false"
    x-on:scroll.passive="trackScrollPosition()"
    class="flex-1 overflow-y-auto px-4 py-6"
>
    <template x-if="messages.length === 0 && !isStreaming">
        <div class="flex h-full items-center justify-center px-6">
            <div class="mx-auto max-w-md text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-900/20 dark:text-primary-400">
                    <x-heroicon-o-sparkles class="h-6 w-6" />
                </div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                    How can I help?
                </h3>
                {{-- The side panel keeps its empty state to the greeting: the record
                     it is bound to already frames what to ask, so the starter chips
                     are noise there. --}}
                @if (($context ?? 'conversation') === 'side-panel')
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Ask about your CRM data.
                    </p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Ask about your CRM data, or try one of these:
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <template x-for="starter in starterPrompts" :key="starter.label">
                            <button
                                type="button"
                                x-on:click="input = starter.prompt; localEditor()?.setText(starter.prompt); $nextTick(() => sendMessage())"
                                x-text="starter.label"
                                class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
                            ></button>
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

    <div class="mx-auto max-w-3xl space-y-6">
        <template x-if="hasMoreMessages">
            <div class="flex justify-center py-2">
                <button
                    type="button"
                    x-on:click="loadEarlier()"
                    class="rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                >
                    {{ __('Load earlier messages') }}
                </button>
            </div>
        </template>

        {{-- Keyed by stable clientKey, NOT index: splices (regenerate/edit),
             prepends (load earlier) and pops (continuation revert) must not
             re-bind DOM nodes across different logical messages. --}}
        <template x-for="(msg, index) in messages" :key="msg.clientKey || ('i-' + index)">
            <div
                class="group/message"
                {{-- Tightens the gap AFTER this message when the NEXT one groups
                     with it (space-y-6 on the parent sets each item's own
                     trailing margin, so the message being pulled closer to its
                     successor is the one whose margin must shrink). --}}
                :class="(index + 1 < messages.length && decorations(index + 1).grouped) ? 'mb-1' : ''"
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
                                <div
                                    :title="msg.created_at ? new Date(msg.created_at).toLocaleString() : ''"
                                    class="[overflow-wrap:anywhere] break-words rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white"
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
                                    class="flex items-center gap-1 px-1 text-[11px]"
                                    :class="msg.sendState === 'failed' ? 'text-red-500 dark:text-red-400' : 'text-primary-100/70 dark:text-gray-500'"
                                >
                                    <template x-if="msg.sendState === 'sending'">
                                        <x-heroicon-o-clock class="h-3 w-3 shrink-0" aria-hidden="true" role="status" aria-label="{{ __('Sending') }}" />
                                    </template>
                                    <template x-if="msg.sendState === 'sent'">
                                        <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" role="status" aria-label="{{ __('Sent') }}" />
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
                                <div class="w-full min-w-[16rem] rounded-2xl rounded-br-md bg-primary-600 p-2">
                                    <label :for="'chat-edit-' + index" class="sr-only">Edit message</label>
                                    <textarea
                                        :id="'chat-edit-' + index"
                                        x-ref="editArea"
                                        x-model="msg.editText"
                                        @input="autosize($event.target)"
                                        @keydown.escape.prevent="cancelEdit(msg)"
                                        @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); saveEdit(msg, index) }"
                                        rows="1"
                                        maxlength="5000"
                                        aria-label="Edit message"
                                        class="block min-h-[28px] w-full resize-none rounded-xl border-0 bg-primary-700/40 px-3 py-2 text-sm leading-6 text-white placeholder:text-primary-100/70 focus:outline-none focus:ring-2 focus:ring-white/40"
                                        style="max-height: 200px;"
                                    ></textarea>
                                    <div class="mt-2 flex items-center justify-between gap-2 px-1">
                                        <span
                                            class="text-[11px]"
                                            :class="{
                                                'text-primary-100/80': (msg.editText || '').length <= 4900,
                                                'text-amber-200': (msg.editText || '').length > 4900 && (msg.editText || '').length <= 5000,
                                                'text-red-200': (msg.editText || '').length > 5000,
                                            }"
                                            x-text="(msg.editText || '').length > 4000 ? `${(msg.editText || '').length.toLocaleString()} / 5,000` : ''"
                                        ></span>
                                        <div class="flex gap-2">
                                            <button
                                                type="button"
                                                x-on:click="cancelEdit(msg)"
                                                class="rounded-lg bg-primary-700/40 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700/70"
                                            >
                                                Cancel
                                            </button>
                                            <button
                                                type="button"
                                                x-on:click="saveEdit(msg, index)"
                                                :disabled="!(msg.editText || '').trim() || (msg.editText || '').length > 5000 || isStreaming"
                                                class="rounded-lg bg-white px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50 disabled:cursor-not-allowed disabled:bg-white/60 disabled:text-primary-400"
                                            >
                                                Save &amp; resend
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!msg.editing && !isStreaming">
                                <div class="flex items-center gap-1 px-1 opacity-0 transition group-hover/message:opacity-100 focus-within:opacity-100">
                                    <button
                                        type="button"
                                        x-on:click="canEdit(index) && startEdit(msg, index)"
                                        :disabled="!canEdit(index)"
                                        :title="canEdit(index) ? 'Edit message' : 'Cannot edit — pending approval'"
                                        :aria-label="canEdit(index) ? 'Edit message' : 'Cannot edit — pending approval'"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Assistant message --}}
                <template x-if="msg.role === 'assistant' && (msg.rendered || msg.content || msg.streamError || (index === messages.length - 1 && isStreaming && currentToolStatus))">
                    <div class="flex flex-col items-start" data-assistant-bubble :data-grouped="decorations(index).grouped">
                        <div class="flex w-full justify-start" x-show="msg.content || !msg.rendered || (index === messages.length - 1 && isStreaming && currentToolStatus)">
                            <div
                                :title="msg.created_at ? 'Completed ' + new Date(msg.created_at).toLocaleString() : ''"
                                class="prose prose-sm dark:prose-invert max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700 prose-headings:text-gray-900 dark:prose-headings:text-white prose-table:my-2 prose-table:border-collapse prose-thead:border-b prose-thead:border-gray-300 dark:prose-thead:border-gray-600 prose-th:px-2 prose-th:py-2 prose-th:text-left prose-td:border-t prose-td:border-gray-100 prose-td:px-2 prose-td:py-2 dark:prose-td:border-gray-700 prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[length:var(--text-micro)] prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-gray-900 prose-pre:rounded-lg prose-pre:bg-gray-900 prose-pre:text-gray-100 first:prose-headings:mt-0"
                            >
                                <template x-if="msg.rendered && msg.prerendered">
                                    <div x-html="msg.content"></div>
                                </template>
                                <template x-if="msg.rendered && !msg.prerendered">
                                    <div x-html="window.renderMarkdown(msg.content)"></div>
                                </template>
                                <template x-if="!msg.rendered">
                                    <div>
                                        <template x-if="msg.content">
                                            <div x-text="msg.content" class="whitespace-pre-wrap"></div>
                                        </template>
                                        <template x-if="index === messages.length - 1 && isStreaming && currentToolStatus">
                                            <div data-chat-loading-indicator class="flex items-center gap-2 text-xs" role="status" :class="{ 'mt-2': msg.content }">
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
                                <span class="flex-1 text-xs text-amber-800 dark:text-amber-200" x-text="msg.streamError"></span>
                                <button
                                    type="button"
                                    x-show="msg.retryable && !isStreaming"
                                    x-on:click="retryTurn(msg)"
                                    class="rounded-md bg-amber-600 px-2 py-1 text-xs font-medium text-white hover:bg-amber-700"
                                >
                                    Retry
                                </button>
                            </div>
                        </template>

                        <template x-if="msg.rendered && Array.isArray(msg.follow_ups) && msg.follow_ups.length > 0">
                            <div class="mt-2 flex flex-wrap gap-2">
                                <template x-for="chip in msg.follow_ups" :key="chip.prompt">
                                    <button
                                        type="button"
                                        x-on:click="input = chip.prompt; localEditor()?.setText(chip.prompt); $nextTick(() => sendMessage())"
                                        x-text="chip.label"
                                        class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
                                    ></button>
                                </template>
                            </div>
                        </template>

                        <template x-if="msg.rendered && msg.content">
                            <div class="mt-1 flex items-center gap-1 px-1 opacity-0 transition group-hover/message:opacity-100 focus-within:opacity-100">
                                <button
                                    type="button"
                                    x-on:click="copyMessage(msg)"
                                    :aria-label="(now - (msg.copiedAt || 0) < 1500) ? 'Copied' : 'Copy message'"
                                    :title="(now - (msg.copiedAt || 0) < 1500) ? 'Copied' : 'Copy message'"
                                    class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                >
                                    <template x-if="now - (msg.copiedAt || 0) < 1500">
                                        <x-heroicon-s-check class="h-3.5 w-3.5 text-green-600 dark:text-green-400" aria-hidden="true" />
                                    </template>
                                    <template x-if="!(now - (msg.copiedAt || 0) < 1500)">
                                        <x-heroicon-o-document-duplicate class="h-3.5 w-3.5" aria-hidden="true" />
                                    </template>
                                </button>
                                <button
                                    type="button"
                                    x-show="!isStreaming"
                                    x-on:click="regenerateMessage(index)"
                                    :disabled="!canRegenerate(index)"
                                    :aria-label="canRegenerate(index) ? 'Regenerate response' : 'Cannot regenerate — pending approval'"
                                    :title="canRegenerate(index) ? 'Regenerate response' : 'Cannot regenerate — pending approval'"
                                    class="inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200 dark:disabled:hover:bg-transparent dark:disabled:hover:text-gray-400"
                                >
                                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" aria-hidden="true" />
                                    <span>Regenerate</span>
                                </button>
                                <template x-if="msg.id">
                                    <span class="flex items-center gap-0.5">
                                        <button
                                            type="button"
                                            x-on:click="rateMessage(msg, 'up')"
                                            :aria-pressed="msg.feedback?.rating === 'up'"
                                            aria-label="Good response"
                                            title="Good response"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md transition hover:bg-gray-100 dark:hover:bg-gray-800"
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
                                            aria-label="Bad response"
                                            title="Bad response"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-md transition hover:bg-gray-100 dark:hover:bg-gray-800"
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
                            <div class="mt-2 w-full max-w-[85%] rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                                <p class="text-xs font-medium text-gray-700 dark:text-gray-300">What went wrong?</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <template x-for="cat in feedbackCategories" :key="cat.value">
                                        <button
                                            type="button"
                                            x-on:click="msg.feedbackCategory = (msg.feedbackCategory === cat.value ? null : cat.value)"
                                            x-text="cat.label"
                                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
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
                                    placeholder="Tell us more (optional)"
                                    aria-label="Feedback details"
                                    class="mt-2 block w-full resize-none rounded-md border-gray-200 bg-white px-2.5 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                ></textarea>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button
                                        type="button"
                                        x-on:click="msg.feedbackPanelOpen = false"
                                        class="rounded-md px-2.5 py-1 text-xs font-medium text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                                    >
                                        Skip
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="submitFeedbackDetail(msg)"
                                        class="rounded-md bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700"
                                    >
                                        Submit
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
                                <a :href="msg.paywall.upgrade_url" class="inline-flex items-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-700">
                                    Add credits
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
                                {{-- Block-renderer registry lookup (packages/Chat/resources/js/chat/blocks.js):
                                     an unregistered type renders nothing, silently. Only 'pending_action' is
                                     registered today, mapped to this proposal-card partial. --}}
                                <template x-if="window.ChatModules.blockTemplate('pending_action') && (action.status !== 'pending' || (action.itemResults && Object.keys(action.itemResults).length > 0))">
                                    @include('chat::livewire.chat.partials._proposal-card')
                                </template>

                                {{-- Agent outcome summary once the proposal is finalized. Reload-safe:
                                     derived from the persisted action by proposalOutcome(), not a stored message. --}}
                                <template x-if="action.status !== 'pending' && proposalOutcome(action)">
                                    <div class="flex justify-start">
                                        <div class="inline-flex max-w-[85%] items-start gap-1.5 rounded-2xl rounded-bl-md bg-white px-3 py-2 text-sm text-gray-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700">
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

        {{-- Pre-token streaming indicator: shimmer label inside an empty assistant bubble --}}
        <template x-if="isStreaming && !currentToolStatus && (messages.length === 0 || messages[messages.length-1].role !== 'assistant' || !messages[messages.length-1].content)">
            <div class="flex justify-start" aria-label="Assistant is thinking" role="status">
                <div class="rounded-2xl rounded-bl-md bg-white px-4 py-3 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div data-chat-loading-indicator class="flex items-center gap-2 text-sm">
                        <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse dark:bg-gray-500" aria-hidden="true"></span>
                        <span data-chat-loading-label x-text="pendingLabel"></span>
                    </div>
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
                x-on:click="jumpToLatest()"
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
