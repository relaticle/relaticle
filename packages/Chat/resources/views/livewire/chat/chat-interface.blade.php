<div
    x-data="chatInterface(@js($conversationId), @js(route('chat.send')), @js($initialMessage), @js($messages), @js(auth()->id()), @js($hasMoreMessages), @js($initialModel ?? auth()->user()?->ai_preferences['default_model'] ?? 'auto'))"
    x-init="init()"
    x-on:chat:focus-editor.window="if ($event.detail?.context === @js($context ?? 'conversation')) localEditor()?.focus()"
    data-chat-context="{{ $context ?? 'conversation' }}"
    data-chat-context-name="{{ $context ?? 'conversation' }}"
    class="relative flex h-full flex-col"
>
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
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Ask about your CRM data, or try one of these:
                    </p>
                    <div class="mt-4 flex flex-wrap justify-center gap-2">
                        <template x-for="prompt in starterPrompts" :key="prompt">
                            <button
                                type="button"
                                x-on:click="input = prompt; localEditor()?.setText(prompt); $nextTick(() => sendMessage())"
                                x-text="prompt"
                                class="rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
                            ></button>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6">
            <template x-if="hasMoreMessages">
                <div class="flex justify-center py-2">
                    <button
                        type="button"
                        x-on:click="loadEarlier()"
                        class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                    >
                        Load earlier messages
                    </button>
                </div>
            </template>

            {{-- Keyed by stable clientKey, NOT index: splices (regenerate/edit),
                 prepends (load earlier) and pops (continuation revert) must not
                 re-bind DOM nodes across different logical messages. --}}
            <template x-for="(msg, index) in messages" :key="msg.clientKey || ('i-' + index)">
                <div class="group/message">
                    {{-- User message --}}
                    <template x-if="msg.role === 'user'">
                        <div class="flex justify-end">
                            <div class="flex max-w-[80%] flex-col items-end gap-1">
                                <template x-if="!msg.editing">
                                    <div
                                        :title="msg.created_at ? new Date(msg.created_at).toLocaleString() : ''"
                                        class="[overflow-wrap:anywhere] break-words rounded-2xl rounded-br-md bg-primary-600 px-4 py-3 text-sm text-white"
                                    >
                                        <span x-html="renderMessageContent(msg)" class="whitespace-pre-wrap"></span>
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
                        <div class="flex flex-col items-start">
                            <div class="flex w-full justify-start" x-show="msg.content || !msg.rendered || (index === messages.length - 1 && isStreaming && currentToolStatus)">
                                <div
                                    :title="msg.created_at ? 'Completed ' + new Date(msg.created_at).toLocaleString() : ''"
                                    class="prose prose-sm dark:prose-invert max-w-[85%] rounded-2xl rounded-bl-md bg-white px-4 py-3 text-gray-900 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700 prose-p:my-2 prose-headings:mb-2 prose-headings:mt-3 prose-headings:text-gray-900 dark:prose-headings:text-white prose-pre:my-2 prose-ul:my-2 prose-ol:my-2 prose-li:my-0.5 prose-table:my-2 prose-table:border-collapse prose-thead:border-b prose-thead:border-gray-300 dark:prose-thead:border-gray-600 prose-th:px-2 prose-th:py-1 prose-th:text-left prose-td:border-t prose-td:border-gray-100 prose-td:px-2 prose-td:py-1 dark:prose-td:border-gray-700 prose-code:rounded prose-code:bg-gray-100 prose-code:px-1 prose-code:py-0.5 prose-code:text-[0.85em] prose-code:before:content-none prose-code:after:content-none dark:prose-code:bg-gray-900 prose-pre:rounded-lg prose-pre:bg-gray-900 prose-pre:text-gray-100 first:prose-headings:mt-0"
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
                                            x-on:click="input = chip.prompt; $nextTick(() => sendMessage())"
                                            x-text="chip.label"
                                            class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
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
                                                class="rounded-full border px-2.5 py-1 text-xs font-medium transition"
                                                :class="msg.feedbackCategory === cat.value
                                                    ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300'
                                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300'"
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
                                    <template x-if="action.status !== 'pending' || (action.itemResults && Object.keys(action.itemResults).length > 0)">
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
    </div>

    {{-- Jump-to-latest pill: new content arrived while reading older messages --}}
    <template x-if="hasUnseenBelow">
        <div class="pointer-events-none absolute inset-x-0 bottom-28 z-30 flex justify-center">
            <button
                type="button"
                x-on:click="jumpToLatest()"
                class="pointer-events-auto flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 shadow-lg transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
            >
                <x-heroicon-o-arrow-down class="h-3.5 w-3.5" aria-hidden="true" />
                New messages
            </button>
        </div>
    </template>

    {{-- Input area --}}
    <div class="border-t border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="mx-auto max-w-3xl">
            {{-- Docked pending proposal: a nested Livewire component hosts the active proposal so it can
                 render real Filament field editors in place (Phase C). Alpine stays the source of truth for
                 whether a proposal is pending and pushes the active id to the card via `proposal:set-active`. --}}
            <div x-show="hasPendingProposal" x-effect="syncActiveProposal()" class="mb-3">
                <div class="mb-1.5 flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-sparkles class="h-3.5 w-3.5" aria-hidden="true" />
                    <span>Review before continuing</span>
                </div>
                <div class="max-h-[55vh] overflow-y-auto">
                    <livewire:chat.proposal-card :context="$context ?? 'conversation'" wire:key="proposal-dock-{{ $context ?? 'conversation' }}" />
                </div>
            </div>

            {{-- Send-throttle countdown: the message is kept and auto-sends --}}
            <template x-if="rateLimit">
                <div class="mb-2 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-900/20" role="status" aria-live="polite">
                    <span class="text-xs text-amber-800 dark:text-amber-200">
                        {{-- Null-safe: this effect can re-evaluate during x-if
                             teardown; throwing here aborts Alpine's flush queue
                             and silently drops queued callbacks. --}}
                        You're sending fast — sending again in <span class="font-semibold tabular-nums" x-text="(rateLimit?.secondsLeft ?? 0) + 's'"></span>
                        <span class="text-amber-700/70 dark:text-amber-300/70" x-text="'· ' + currentPlanLabel + ' plan'"></span>
                    </span>
                    <button
                        type="button"
                        x-on:click="clearRateLimit()"
                        class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900/40"
                    >
                        Cancel
                    </button>
                </div>
            </template>

            <form x-show="!hasPendingProposal" x-on:submit.prevent="sendMessage()">
                <div
                    x-data="chatEditor({
                        initialDocument: { type: 'doc', content: [] },
                        placeholder: 'Ask anything...',
                        autofocus: @js(($context ?? 'conversation') !== 'side-panel'),
                        onSubmit: () => $root.dispatchEvent(new CustomEvent('chat:editor-submit', { bubbles: true })),
                        onChange: ({ document, text }) => {
                            $root.dispatchEvent(new CustomEvent('chat:editor-change', { bubbles: true, detail: { document, text } }));
                        },
                    })"
                    x-on:chat:editor-submit.window="sendMessage()"
                    x-on:chat:editor-change.window="input = $event.detail.text"
                    {{-- No global setter needed — chatInterface uses localEditor() to scope-resolve. --}}
                    data-chat-context="{{ $context ?? 'conversation' }}"
                    class="relative rounded-2xl border border-gray-200 bg-white transition focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800"
                >
                    {{-- wire:ignore: TipTap mounts into this node imperatively; without it
                         Livewire's morphdom wipes the editor on every chat-interface re-render
                         (e.g. after the first message), leaving an empty, unusable composer. --}}
                    <div x-ref="editor" class="relative" wire:ignore></div>

                    <div class="flex items-center justify-between gap-2 px-3 pb-2">
                        <span
                            x-show="text.length > 4000"
                            x-cloak
                            x-text="`${text.length.toLocaleString()} / 5,000`"
                            :class="{
                                'text-gray-500 dark:text-gray-400': text.length <= 4900,
                                'text-amber-600 dark:text-amber-400': text.length > 4900 && text.length <= 5000,
                                'text-red-600 dark:text-red-400': text.length > 5000,
                            }"
                            class="text-[11px]"
                            aria-live="polite"
                        ></span>
                        <div x-show="text.length <= 4000" class="flex-1"></div>

                        <div class="flex items-center gap-2">
                            @include('chat::livewire.chat.partials._model-picker')

                            <button
                                x-show="!isStreaming"
                                type="submit"
                                class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                                :disabled="text.trim().length === 0 || text.length > 5000 || rateLimit !== null"
                                aria-label="Send message"
                            >
                                <x-heroicon-s-arrow-up class="h-4 w-4" />
                            </button>
                            <button
                                x-show="isStreaming"
                                type="button"
                                x-on:click="cancelStream()"
                                class="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-900 text-white transition hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-gray-300"
                                aria-label="Stop generation"
                            >
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <rect x="6" y="6" width="12" height="12" rx="2"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@script
<script>
Alpine.data('chatInterface', (initialConversationId, sendUrl, initialMessage, initialMessages, userId, initialHasMoreMessages, initialModel) => ({
    conversationId: initialConversationId,
    context: 'conversation',
    messages: initialMessages || [],
    hasMoreMessages: !!initialHasMoreMessages,
    input: '',
    isStreaming: false,
    channel: null,
    streamTimeoutId: null,
    streamTimeoutMs: 60000,
    prependScrollAnchor: null,
    streamAbortController: null,
    currentToolStatus: null,
    now: Date.now(),
    copyTickerId: null,
    currentPlan: @js(auth()->user()?->currentTeam?->plan?->value ?? \App\Enums\Plan::default()->value),
    currentPlanLabel: @js(auth()->user()?->currentTeam?->plan?->label() ?? \App\Enums\Plan::default()->label()),
    allowedModels: @js(
        collect((auth()->user()?->currentTeam?->plan ?? \App\Enums\Plan::default())->allowedModels())
            ->map(fn ($m) => $m->value)
            ->all()
    ),
    selectedModel: 'auto',

    // Bridge state for the docked livewire proposal-card. _lastActiveProposalId
    // dedupes proposal:set-active dispatches.
    _lastActiveProposalId: null,
    // When the user types + sends during an active stream, we stash the
    // message here, clear the editor (so they see their intent was accepted),
    // and auto-flush this on handleStreamEnd / cancel / failure.
    queuedSend: null,

    // Active send-throttle window: {secondsLeft, timerId, document}. While set,
    // the composer is soft-disabled with a countdown and the stashed message
    // auto-sends the moment the window opens. Cancel keeps the text, drops the
    // auto-send.
    rateLimit: null,

    // Scroll ownership (see scrollToBottom): streaming only autoscrolls while
    // the user is pinned near the bottom; otherwise the jump pill shows.
    pinnedToBottom: true,
    hasUnseenBelow: false,

    // Scoped lookup of THIS chat-interface's TipTap editor. Avoids the
    // window.__chatEditor global that breaks when multiple chat-interface
    // instances render simultaneously (e.g. side panel + main page).
    //
    // We deliberately use `document.querySelector` scoped by data-chat-context
    // rather than `this.$root.querySelector` because Livewire's morphdom can
    // briefly detach children from the chat-interface root during a re-render,
    // and `this.$root.querySelector` returns null for the editor wrapper in
    // that window — which is exactly when sendMessage() needs it most to
    // call clear() after a send. Both this.$root and the chatEditor wrapper
    // expose data-chat-context, so the selector is unambiguous.
    // Documents stashed in Alpine state come back as reactive Proxies, and
    // TipTap's setDocument structuredClones its input — Proxies cannot be
    // structuredCloned, so the call throws and silently kills whatever line
    // was next (this lost queued messages AND broke rate-limit auto-send
    // before it was found). Always unwrap to plain JSON first.
    plainDocument(doc) {
        if (!doc) return null;
        try {
            return JSON.parse(JSON.stringify(doc));
        } catch (_) {
            return null;
        }
    },

    // Stable identity for the x-for key: server id when persisted, otherwise a
    // minted client uuid that survives reconciliation (never reassigned).
    ensureClientKey(m) {
        if (!m.clientKey) {
            m.clientKey = m.id || ('c-' + (window.crypto?.randomUUID?.() ?? (Date.now() + '-' + Math.random())));
        }
        return m;
    },

    localEditor() {
        const ctx = (this.$root || this.$el)?.getAttribute?.('data-chat-context') ?? 'conversation';
        const wrapper = document.querySelector(`[data-chat-context="${ctx}"][x-data*="chatEditor"]`);
        if (! wrapper || ! window.Alpine) return null;
        return window.Alpine.$data(wrapper);
    },

    // Single source of truth for the assistant-bubble shape. Every streamed
    // turn renders into a stub minted here; invocationId binds the bubble to
    // one laravel/ai stream() call (fresh uuid per job attempt), which is what
    // prevents retry re-streams and later turns from appending into it.
    mintAssistantStub(extra = {}) {
        const stub = {
            role: 'assistant',
            content: '',
            pending_actions: [],
            paywall: null,
            sessionExpired: false,
            rendered: false,
            prerendered: false,
            copiedAt: 0,
            follow_ups: [],
            created_at: new Date().toISOString(),
            invocationId: null,
            streamError: null,
            retryable: false,
            _needsSeparator: false,
            feedback: null,
            feedbackPanelOpen: false,
            feedbackCategory: null,
            feedbackComment: '',
            ...extra,
        };
        this.ensureClientKey(stub);
        this.messages.push(stub);
        return stub;
    },

    lastAssistantBubble() {
        for (let i = this.messages.length - 1; i >= 0; i--) {
            if (this.messages[i].role === 'assistant') return this.messages[i];
        }
        return null;
    },

    // Resolve which bubble a stream event belongs to.
    //  - exact invocation match anywhere -> that bubble (trailing deltas of a
    //    still-open turn keep landing in THEIR bubble even after a continuation
    //    stub was minted after it)
    //  - unbound in-flight stub -> bind it (first event of the turn)
    //  - different invocation on an UNRENDERED bubble -> the job retried and is
    //    re-streaming from the top: reset the partial state, rebind (de-dupes)
    //  - otherwise (last bubble already rendered) -> a turn we never minted a
    //    stub for (e.g. resume) -> mint one bound to this invocation
    targetBubbleFor(invocationId) {
        if (invocationId !== null) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === invocationId) return m;
            }
        }
        const b = this.lastAssistantBubble();
        if (b && !b.rendered) {
            if (b.invocationId == null) {
                b.invocationId = invocationId;
                return b;
            }
            b.invocationId = invocationId;
            b.content = '';
            b._needsSeparator = false;
            b.pending_actions = [];
            b.paywall = null;
            b.streamError = null;
            return b;
        }
        return this.mintAssistantStub({ invocationId });
    },

    modelOptions: [
        { value: 'auto', label: 'Auto', provider: null },
        { value: 'claude-sonnet', label: 'Sonnet 4.6', provider: 'anthropic' },
        { value: 'claude-opus', label: 'Opus 4.7', provider: 'anthropic' },
        { value: 'gpt-5-5', label: 'GPT 5.5', provider: 'openai' },
        { value: 'gpt-5-4', label: 'GPT 5.4', provider: 'openai' },
    ],

    providerIcons: @js([
        'anthropic' => svg('ri-claude-fill')->toHtml(),
        'openai' => svg('ri-openai-fill')->toHtml(),
    ]),

    providerIconHtml(provider) {
        if (!provider) return '';
        return this.providerIcons[provider] || '';
    },

    providerIconColor(provider) {
        return ({
            anthropic: 'text-[#D4763C]',
            openai: 'text-gray-900 dark:text-gray-200',
        })[provider] || '';
    },

    modelLabel(value) {
        const found = this.modelOptions.find((o) => o.value === value);
        return (found || this.modelOptions[0]).label;
    },

    modelProvider(value) {
        const found = this.modelOptions.find((o) => o.value === value);
        return found?.provider ?? null;
    },

    selectModel(value) {
        if (! this.allowedModels.includes(value)) {
            window.dispatchEvent(new CustomEvent('chat:model-locked', {
                detail: { model: value, plan: this.currentPlan, planLabel: this.currentPlanLabel },
            }));
            return;
        }
        this.selectedModel = value;
        try { localStorage.setItem('chat:model', value); } catch (_) { /* ignore */ }
    },

    starterPrompts: [
        'Give me a CRM overview',
        'Show overdue tasks',
        'Recent companies',
        'Pipeline summary',
    ],

    autosize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 200) + 'px';
    },

    renderMessageContent(message) {
        if (!message.document || (Array.isArray(message.document.content) && message.document.content.length === 0)) {
            return this.escapeHtml(message.content ?? '');
        }

        // Server-resolved mention URLs are authoritative. When a message carries a
        // `mentions` array (loaded from the backend) links are rendered ONLY from
        // it — never from the client-controlled document. A just-sent (optimistic)
        // message has no server mentions yet, so it falls back to the picker URL
        // stored on the node, which is still scheme-validated before use.
        const serverMentions = Array.isArray(message.mentions) ? message.mentions : null;
        const mentionUrls = {};
        (serverMentions ?? []).forEach((m) => {
            if (m && m.id != null && m.url) {
                mentionUrls[String(m.id)] = m.url;
            }
        });

        return this.walkDocumentToHtml(message.document, { mentionUrls, allowNodeUrl: serverMentions === null });
    },

    walkDocumentToHtml(node, ctx = {}) {
        if (!node) return '';
        if (node.type === 'doc') {
            return (node.content ?? []).map((c) => this.walkDocumentToHtml(c, ctx)).join('');
        }
        if (node.type === 'paragraph') {
            const children = (node.content ?? []).map((c) => this.walkDocumentToHtml(c, ctx)).join('');
            return `<p>${children}</p>`;
        }
        if (node.type === 'text') {
            return this.escapeHtml(node.text ?? '');
        }
        if (node.type === 'mention') {
            return this.renderMentionNode(node, ctx);
        }
        if (node.type === 'hardBreak') {
            return '<br>';
        }
        return '';
    },

    renderMentionNode(node, ctx = {}) {
        const id = node.attrs?.id ?? '';
        const idAttr = this.escapeAttr(id);
        const type = this.escapeAttr(node.attrs?.type ?? '');
        const label = this.escapeHtml(node.attrs?.label ?? '');
        const baseClass = 'inline-flex items-center rounded-md bg-primary-100 px-1.5 py-0.5 text-xs text-primary-800 dark:bg-primary-900/30 dark:text-primary-200';

        let url = ctx.mentionUrls?.[String(id)] ?? null;
        if (!url && ctx.allowNodeUrl) {
            url = node.attrs?.url ?? null;
        }

        if (url && this.isSafeUrl(url)) {
            const href = this.escapeAttr(url);
            return `<a href="${href}" target="_blank" rel="noopener noreferrer" data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass} no-underline transition-colors hover:bg-primary-200 dark:hover:bg-primary-900/60">@${label}</a>`;
        }

        return `<span data-mention-id="${idAttr}" data-mention-type="${type}" class="${baseClass}">@${label}</span>`;
    },

    isSafeUrl(url) {
        if (typeof url !== 'string' || url === '') return false;
        // Root-relative ("/app/...") URLs are same-origin and safe; reject the
        // protocol-relative "//host" form which would point off-origin.
        if (url.startsWith('//')) return false;
        if (url.startsWith('/')) return true;
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.protocol === 'http:' || parsed.protocol === 'https:';
        } catch (_) {
            return false;
        }
    },

    escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    },

    escapeAttr(str) {
        return this.escapeHtml(str);
    },

    init() {
        this.context = this.$root?.dataset?.chatContextName ?? 'conversation';

        const validModels = this.modelOptions
            .map((o) => o.value)
            .filter((v) => this.allowedModels.includes(v));
        let stored = null;
        try { stored = localStorage.getItem('chat:model'); } catch (_) { /* ignore */ }
        const candidate = stored || initialModel || 'auto';
        this.selectedModel = validModels.includes(candidate) ? candidate : 'auto';

        this.messages.forEach((m) => {
            this.ensureClientKey(m);
            if (m.role === 'assistant') {
                m.rendered = true;
                m.prerendered = true;
                if (!Array.isArray(m.follow_ups)) {
                    m.follow_ups = [];
                }
                m.feedback = m.feedback ?? null;
                m.feedbackPanelOpen = false;
                m.feedbackCategory = m.feedback?.category ?? null;
                m.feedbackComment = '';
            }
            if (m.role === 'user') {
                m.editing = false;
                m.editText = '';
            }
            if (typeof m.copiedAt === 'undefined') {
                m.copiedAt = 0;
            }
        });

        if (this.conversationId) {
            this.subscribeToConversation(this.conversationId);
        }

        // Land at the latest message when reopening an existing conversation.
        // Without this, the messages container starts scrolled to the top
        // (oldest message), forcing the user to scroll down to see context.
        if (this.messages.length > 0) {
            this.scrollToBottom(true);
        }

        // Bootstrap payload from the dashboard: when the user submits their
        // first message there, we stash the editor document in sessionStorage
        // and navigate immediately. Restore the document (preserves mentions)
        // and fire sendMessage() so this page does the actual POST without a
        // server round-trip blocking the navigation.
        try {
            const raw = sessionStorage.getItem('chat:bootstrap');
            if (raw && !this.conversationId) {
                sessionStorage.removeItem('chat:bootstrap');
                const parsed = JSON.parse(raw);
                const bootstrapDoc = parsed?.document;
                const bootstrapModel = parsed?.model;

                if (bootstrapModel && this.modelOptions.some((o) => o.value === bootstrapModel)) {
                    this.selectedModel = bootstrapModel;
                }

                if (bootstrapDoc) {
                    this.$nextTick(() => {
                        this.localEditor()?.setDocument?.(bootstrapDoc);
                        this.sendMessage();
                    });
                }
            }
        } catch (_) { /* sessionStorage unavailable or malformed payload */ }

        if (initialMessage) {
            this.$nextTick(() => {
                this.input = initialMessage;
                this.localEditor()?.setText(initialMessage);
                this.sendMessage();
            });
        }

        try {
            const draft = localStorage.getItem('chat:draft');
            if (draft) {
                this.input = draft;
                this.$nextTick(() => this.localEditor()?.setText(draft));
                localStorage.removeItem('chat:draft');
            }
        } catch (_) { /* ignore */ }

        this.beforeUnloadHandler = (e) => {
            if (!this.isStreaming) return;
            e.preventDefault();
            e.returnValue = 'Your message is still being generated. Leave anyway?';
        };
        window.addEventListener('beforeunload', this.beforeUnloadHandler);

        this.approvalKeyHandler = (e) => {
            if (!((e.metaKey || e.ctrlKey) && e.key === 'Enter')) return;

            const pending = this.visiblePendingActions();
            if (pending.length !== 1 || this.isStreaming) return;
            if (this.input.trim().length > 0) return; // composer draft wins

            e.preventDefault();
            if (window.Livewire?.dispatch) {
                window.Livewire.dispatch('proposal:create-current', { context: this.context });
            }
        };
        window.addEventListener('keydown', this.approvalKeyHandler);

        this.renamedHandler = (e) => {
            const detail = e.detail || {};
            if (!detail.conversationId || detail.conversationId !== this.conversationId) return;

            // Update document.title for the browser tab.
            document.title = `${detail.title || 'Untitled'} - Relaticle`;

            // Update the visible H1 if present (Filament page header).
            const h1 = document.querySelector('main h1');
            if (h1 && detail.title) {
                h1.textContent = detail.title;
            }
        };
        window.addEventListener('chat:renamed', this.renamedHandler);

        this.$wire.$on('chat:messages-prepended', (payload) => {
            const earlier = (payload && payload.messages) || [];
            const hasMore = payload ? !!payload.hasMore : false;
            if (earlier.length > 0) {
                earlier.forEach((m) => {
                    this.ensureClientKey(m);
                    if (m.role === 'assistant') {
                        m.rendered = true;
                        m.prerendered = true;
                        if (!Array.isArray(m.follow_ups)) {
                            m.follow_ups = [];
                        }
                        m.feedback = m.feedback ?? null;
                        m.feedbackPanelOpen = false;
                        m.feedbackCategory = m.feedback?.category ?? null;
                        m.feedbackComment = '';
                    }
                    if (m.role === 'user') {
                        m.editing = false;
                        m.editText = '';
                    }
                    if (typeof m.copiedAt === 'undefined') {
                        m.copiedAt = 0;
                    }
                });
                this.messages = [...earlier, ...this.messages];
            }
            this.hasMoreMessages = hasMore;

            this.$nextTick(() => {
                const el = this.$refs.messages;
                if (!el || this.prependScrollAnchor === null) return;
                el.scrollTop = el.scrollHeight - this.prependScrollAnchor;
                this.prependScrollAnchor = null;
            });
        });

        // Bridge the docked livewire proposal-card's resolution lifecycle back
        // into Alpine state. window.Livewire.on returns an unsubscribe fn (v4);
        // named-arg dispatches arrive as a single params object (e.detail).
        this._proposalListeners = [
            window.Livewire.on('proposal:resolved', (payload) => {
                if ((payload?.context ?? 'conversation') !== this.context) return;
                this.applyProposalResolution(payload);
            }),
            window.Livewire.on('proposal:resolve-failed', (payload) => {
                if ((payload?.context ?? 'conversation') !== this.context) return;
                const action = this.findPendingAction(payload?.pendingActionId);
                if (action) action.error = 'Could not complete the action. Please try again.';
            }),
        ];

        // Pre-seed a mention chip when "Ask about this" action stores a mention
        // in sessionStorage before opening the side panel. The chat:focus-editor
        // event fires after the panel becomes visible and the editor is mounted.
        this.mentionHandler = (e) => {
            if (e.detail?.context !== this.context) return;
            try {
                const raw = sessionStorage.getItem('chat:mention');
                if (!raw) return;
                sessionStorage.removeItem('chat:mention');
                const m = JSON.parse(raw);
                if (!m?.type || !m?.id || !m?.label) return;
                this.$nextTick(() => {
                    this.localEditor()?.setDocument?.({
                        type: 'doc',
                        content: [{
                            type: 'paragraph',
                            content: [{
                                type: 'mention',
                                attrs: { type: m.type, id: m.id, label: m.label },
                            }],
                        }],
                    });
                    this.localEditor()?.focus?.();
                });
            } catch (_) { /* sessionStorage unavailable or malformed payload */ }
        };
        window.addEventListener('chat:focus-editor', this.mentionHandler);
    },

    loadEarlier() {
        const el = this.$refs.messages;
        this.prependScrollAnchor = el ? el.scrollHeight : 0;
        this.$wire.loadEarlierMessages();
    },

    visiblePendingActions() {
        return this.messages
            .flatMap((m) => m.pending_actions || [])
            .filter((a) => a.status === 'pending');
    },

    activePendingActionId() {
        const pending = this.visiblePendingActions();
        return pending.length > 0 ? pending[0].pending_action_id : null;
    },

    syncActiveProposal() {
        const id = this.activePendingActionId();
        if (id === this._lastActiveProposalId) return;
        this._lastActiveProposalId = id;
        if (window.Livewire?.dispatch) {
            window.Livewire.dispatch('proposal:set-active', { id, context: this.context });
        }
    },

    get hasPendingProposal() {
        return this.visiblePendingActions().length > 0;
    },

    findPendingAction(id) {
        for (const m of this.messages) {
            const found = (m.pending_actions || []).find((a) => a.pending_action_id === id);
            if (found) return found;
        }
        return null;
    },

    applyProposalResolution(payload) {
        const action = this.findPendingAction(payload.pendingActionId);
        if (!action) return;
        action.error = null;

        if (payload.index === null || payload.index === undefined) {
            // Single proposal.
            action.status = payload.decision === 'approved' ? 'approved' : 'rejected';
            if (payload.record) action.record = payload.record;
        } else {
            // Batch item: the transcript renders per-item status 'approved'/'skipped'.
            action.itemResults = {
                ...action.itemResults,
                [payload.index]: {
                    status: payload.decision === 'approved' ? 'approved' : 'skipped',
                    record: payload.record || null,
                },
            };
            if (payload.finalized) action.status = 'approved';
        }

        if (payload.decision === 'approved' && window.Livewire?.dispatch) {
            window.Livewire.dispatch('ai-write-completed', {
                entityType: action.entity_type ?? null,
                operation: action.operation ?? null,
            });
        }
    },

    destroy() {
        this.clearStreamTimeout();
        this.stopCopyTicker();
        this.clearRateLimit();
        this.unsubscribe();
        window.removeEventListener('beforeunload', this.beforeUnloadHandler);
        window.removeEventListener('chat:renamed', this.renamedHandler);
        window.removeEventListener('keydown', this.approvalKeyHandler);
        window.removeEventListener('chat:focus-editor', this.mentionHandler);
        (this._proposalListeners || []).forEach((off) => typeof off === 'function' && off());
    },

    startCopyTicker() {
        if (this.copyTickerId) return;
        this.copyTickerId = setInterval(() => {
            this.now = Date.now();
            const stillActive = this.messages.some((m) => m.copiedAt && this.now - m.copiedAt < 1500);
            if (!stillActive) {
                this.stopCopyTicker();
            }
        }, 200);
    },

    stopCopyTicker() {
        if (this.copyTickerId) {
            clearInterval(this.copyTickerId);
            this.copyTickerId = null;
        }
    },

    async copyMessage(msg) {
        const text = msg?.content || '';
        if (!text) return;

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
            msg.copiedAt = Date.now();
            this.now = msg.copiedAt;
            this.startCopyTicker();
        } catch (_) { /* clipboard blocked — silently ignore */ }
    },

    feedbackCategories: [
        { value: 'inaccurate', label: 'Inaccurate' },
        { value: 'did_not_follow', label: "Didn't do what I asked" },
        { value: 'too_slow', label: 'Too slow' },
        { value: 'other', label: 'Other' },
    ],

    // Thumbs funnel: up = one tap; down = rating recorded immediately, then an
    // optional category/comment panel. Tapping the active thumb retracts.
    async rateMessage(msg, rating) {
        if (!msg.id) return;

        if (msg.feedback?.rating === rating) {
            msg.feedback = null;
            msg.feedbackPanelOpen = false;
            await this.postFeedback(msg, null);
            return;
        }

        msg.feedback = { rating, category: null };
        msg.feedbackPanelOpen = rating === 'down';
        msg.feedbackCategory = null;
        msg.feedbackComment = '';
        await this.postFeedback(msg, { rating });
    },

    async submitFeedbackDetail(msg) {
        if (!msg.id || msg.feedback?.rating !== 'down') {
            msg.feedbackPanelOpen = false;
            return;
        }
        msg.feedback = { rating: 'down', category: msg.feedbackCategory ?? null };
        msg.feedbackPanelOpen = false;
        await this.postFeedback(msg, {
            rating: 'down',
            category: msg.feedbackCategory ?? null,
            comment: (msg.feedbackComment || '').trim() || null,
        });
    },

    async postFeedback(msg, payload) {
        try {
            const url = @js(url('/chat/messages')) + '/' + msg.id + '/feedback';
            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            };
            if (payload === null) {
                await fetch(url, { method: 'DELETE', headers });
                return;
            }
            await fetch(url, { method: 'POST', headers, body: JSON.stringify(payload) });
        } catch (_) { /* fire-and-forget — never block the conversation on feedback */ }
    },

    canRegenerate(index) {
        const msg = this.messages[index];
        if (msg?.pending_actions?.some((a) => a.status === 'pending')) {
            return false;
        }
        for (let i = index - 1; i >= 0; i--) {
            if (this.messages[i].role === 'user') {
                return true;
            }
        }
        return false;
    },

    async regenerateMessage(index) {
        if (this.isStreaming) return;

        let userIndex = -1;
        for (let i = index - 1; i >= 0; i--) {
            if (this.messages[i].role === 'user') {
                userIndex = i;
                break;
            }
        }
        if (userIndex === -1) return;

        const userMsg = this.messages[userIndex];
        if (!(await this.supersedeServerTurns(userMsg))) return;

        const userText = userMsg.content;
        this.messages.splice(userIndex);

        this.input = userText;
        this.localEditor()?.setText(userText);
        this.$nextTick(() => this.sendMessage());
    },

    // Tell the server the turns from this user message onward are replaced.
    // Without this, the local splice is cosmetic: reload resurrects the old
    // turns (with the user row duplicated) and the model keeps them in its
    // history. Persisted messages anchor by id; optimistic ones (no id yet)
    // anchor by content against the latest user row server-side.
    async supersedeServerTurns(userMsg) {
        if (!this.conversationId) return true;
        try {
            const res = await fetch(@js(url('/chat/conversations')) + '/' + this.conversationId + '/messages/supersede', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    anchor_id: userMsg.id ?? null,
                    anchor_content: userMsg.id ? null : (userMsg.content || null),
                }),
            });
            return res.ok;
        } catch {
            return false;
        }
    },

    canEdit(index) {
        if (this.isStreaming) return false;

        for (let i = index + 1; i < this.messages.length; i++) {
            const next = this.messages[i];
            if (next.role !== 'assistant') continue;
            const hasPending = (next.pending_actions || []).some((a) => a.status === 'pending');
            if (hasPending) return false;
            break;
        }
        return true;
    },

    startEdit(msg, index) {
        if (!this.canEdit(index)) return;
        this.messages.forEach((m) => {
            if (m.role === 'user' && m.editing) {
                m.editing = false;
                m.editText = '';
            }
        });
        msg.editText = msg.content;
        msg.editing = true;

        this.$nextTick(() => {
            const el = this.$refs.editArea;
            if (!el) return;
            el.focus();
            el.setSelectionRange(el.value.length, el.value.length);
            this.autosize(el);
        });
    },

    cancelEdit(msg) {
        msg.editing = false;
        msg.editText = '';
    },

    async saveEdit(msg, index) {
        if (this.isStreaming) return;

        const newText = (msg.editText || '').trim();
        if (!newText || newText.length > 5000) return;

        if (!(await this.supersedeServerTurns(msg))) return;

        this.messages.splice(index);

        this.input = newText;
        this.localEditor()?.setText(newText);
        this.$nextTick(() => this.sendMessage());
    },

    unsubscribe() {
        if (this.channel && window.Echo) {
            window.Echo.leave(this.channel.name);
            this.channel = null;
        }
    },

    subscribeToConversation(conversationId) {
        if (!window.Echo) return Promise.resolve();
        if (this.channel && this.channel.conversationId === conversationId) {
            return this.channel.subscribed ? Promise.resolve() : (this.channel.readyPromise || Promise.resolve());
        }

        this.unsubscribe();

        const channelName = `chat.conversation.${conversationId}`;
        this.channel = window.Echo.private(channelName);
        this.channel.name = channelName;
        this.channel.conversationId = conversationId;
        this.channel.subscribed = false;

        const readyPromise = new Promise((resolve) => {
            const pusherChannel = this.channel.subscription ?? this.channel;
            let settled = false;
            const finish = (confirmed) => {
                if (settled) return;
                settled = true;
                this.channel.subscribed = confirmed;
                resolve(confirmed);
            };
            if (typeof pusherChannel.bind === 'function') {
                pusherChannel.bind('pusher:subscription_succeeded', () => finish(true));
                pusherChannel.bind('pusher:subscription_error', () => finish(false));
                // Bounded fallback: proceed unconfirmed after 8s, but stream_end
                // reconciliation (handleStreamEnd) guarantees the final message is
                // correct even if early deltas were missed.
                setTimeout(() => finish(false), 8000);
            } else {
                finish(true);
            }
        });

        this.channel.readyPromise = readyPromise;

        this.channel
            .listen('.stream_start', (e) => this.handleStreamStart(e))
            .listen('.text_delta', (e) => this.handleTextDelta(e))
            .listen('.tool_call', (e) => this.handleToolCall(e))
            .listen('.tool_result', (e) => this.handleToolResult(e))
            .listen('.stream_end', (e) => this.handleStreamEnd(e))
            .listen('.stream.failed', (e) => this.handleStreamFailed(e))
            .listen('.stream.retrying', (e) => this.handleStreamRetrying(e))
            .listen('.conversation.resolved', (e) => this.handleConversationResolved(e))
            .listen('.follow_ups', (e) => this.handleFollowUps(e))
            .listen('.pending_actions_superseded', (e) => this.handlePendingActionsSuperseded(e));

        return readyPromise;
    },

    handleFollowUps(event) {
        const chips = Array.isArray(event?.chips) ? event.chips.slice(0, 3) : [];
        // Chips belong to the turn that just COMPLETED. If a queued send
        // already minted a fresh stub, the last assistant bubble is the wrong
        // (unstarted) one — attach to the last rendered bubble instead.
        for (let i = this.messages.length - 1; i >= 0; i--) {
            const m = this.messages[i];
            if (m.role === 'assistant' && m.rendered) {
                m.follow_ups = chips;
                return;
            }
        }
        const last = this.lastAssistantBubble();
        if (last) last.follow_ups = chips;
    },

    // Server marked pending actions as superseded (user sent a new message without
    // acting on them). Update the local cards by id so the UI reflects state even
    // if our optimistic mark missed something.
    handlePendingActionsSuperseded(event) {
        const ids = Array.isArray(event?.ids) ? new Set(event.ids) : null;
        if (!ids || ids.size === 0) return;
        this.markPendingActionsSuperseded(ids);
    },

    // Optimistic local supersede when the user sends a new message. The server
    // confirms via .pending_actions_superseded; both paths converge on the same
    // visual state so a single broadcast loss doesn't leave stale "pending" CTAs.
    markPendingActionsSuperseded(idFilter = null) {
        for (const msg of this.messages) {
            if (msg.role !== 'assistant' || !Array.isArray(msg.pending_actions)) continue;
            for (const action of msg.pending_actions) {
                if (action.status !== 'pending') continue;
                if (idFilter && !idFilter.has(action.pending_action_id)) continue;
                action.status = 'superseded';
                action.error = null;
            }
        }
    },

    get pendingLabel() {
        return this.currentToolStatus ?? 'Thinking…';
    },

    friendlyToolStatus(toolName) {
        if (!toolName) return 'Running tool…';
        const normalized = String(toolName)
            .replace(/Tool$/, '')
            .replace(/([a-z])([A-Z])/g, '$1_$2')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1_$2')
            .toLowerCase();

        if (normalized === 'get_crm_summary') return 'Reading CRM summary…';
        if (normalized === 'search_crm') return 'Searching CRM…';

        const m = normalized.match(/^(list|get|create|update|delete)_(.+)$/);
        if (!m) return `Running ${normalized}…`;

        const [, op, rest] = m;
        const entity = rest.replace(/_/g, ' ');

        if (op === 'list') return `Searching ${entity}…`;
        if (op === 'get') return `Looking up ${entity}…`;
        return `Preparing ${op} ${entity} proposal…`;
    },

    startStreamTimeout(timeoutMs = null) {
        this.clearStreamTimeout();
        this.streamTimeoutId = setTimeout(async () => {
            if (!this.isStreaming) return;
            // A lost stream_end stranded this turn: reconcile from the DB so the
            // final text AND any proposal card self-heal instead of showing a
            // truncated bubble with a missing approve/reject CTA until reload.
            const assistantMsg = this.lastAssistantBubble();
            await this.reconcileLatestAssistant(assistantMsg);
            if (assistantMsg?.role === 'assistant') {
                if (!assistantMsg.content) {
                    assistantMsg.streamError = 'The assistant took too long to respond.';
                    assistantMsg.retryable = true;
                }
                assistantMsg.rendered = true;
                assistantMsg.prerendered = false;
            }
            this.currentToolStatus = null;
            this.isStreaming = false;
            this.restoreInputFocus();
        }, timeoutMs ?? this.streamTimeoutMs);
    },

    clearStreamTimeout() {
        if (this.streamTimeoutId) {
            clearTimeout(this.streamTimeoutId);
            this.streamTimeoutId = null;
        }
    },

    documentFromInput(text) {
        const trimmed = text.trim();
        if (trimmed === '') {
            return { type: 'doc', content: [] };
        }
        return {
            type: 'doc',
            content: [{
                type: 'paragraph',
                content: [{ type: 'text', text: trimmed }],
            }],
        };
    },

    async sendMessage() {
        if (this.hasPendingProposal) return;
        if (this.rateLimit) return;

        const editor = this.localEditor();
        const text = (editor?.getText() ?? this.input).trim();
        if (!text) return;
        if (text.length > 5000) return;

        // If a previous turn is still streaming, queue this message and clear
        // the editor so the user sees their intent was accepted. handleStreamEnd
        // (or cancel / failure) will flush this queue.
        if (this.isStreaming) {
            this.queuedSend = {
                document: this.localEditor()?.getDocument() ?? this.documentFromInput(text),
                model: this.selectedModel,
            };
            this.localEditor()?.clear();
            this.input = '';
            return;
        }

        // Claim the lock SYNCHRONOUSLY so a second tick of sendMessage() bails at the
        // guard above. Any failure path between here and the existing isStreaming=false
        // resets must keep that invariant.
        this.isStreaming = true;

        // The user moved on without acting on any prior proposals. Server will
        // confirm via .pending_actions_superseded; we update locally so the
        // approve/reject buttons disappear immediately.
        this.markPendingActionsSuperseded();

        const isFirstMessage = !this.conversationId;
        const payload = this.localEditor()?.getDocument() ?? this.documentFromInput(text);

        if (isFirstMessage) {
            const nowIso = new Date().toISOString();
            this.messages.push(this.ensureClientKey({ role: 'user', content: text, document: payload, editing: false, editText: '', copiedAt: 0, created_at: nowIso }));
            this.mintAssistantStub();
            this.localEditor()?.clear();
            this.input = '';
            this.currentToolStatus = null;

            let newId = null;
            try {
                // Step 1: create the conversation row. Server returns the id
                // immediately without dispatching the AI job. Channel auth
                // requires this row to exist, so we must complete this before
                // attempting to subscribe.
                const createRes = await fetch(@js(route('chat.conversations.create')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        document: payload,
                        model: this.selectedModel !== 'auto' ? this.selectedModel : undefined,
                    }),
                });

                if (!createRes.ok) {
                    const body = await createRes.json().catch(() => ({}));

                    if (createRes.status === 429 && body?.error === 'rate_limited') {
                        this.handleSendRateLimit(body, payload);
                        return;
                    }

                    const assistantMsg = this.messages[this.messages.length - 1];

                    if (createRes.status === 401 || createRes.status === 419) {
                        try { localStorage.setItem('chat:draft', text); } catch (_) { /* ignore */ }
                        assistantMsg.content = 'Your session expired. Please sign in again — your message is saved locally.';
                        assistantMsg.sessionExpired = true;
                    } else {
                        assistantMsg.content = body?.errors?.document?.[0] ?? body?.message ?? `Error ${createRes.status}: ${createRes.statusText}`;
                    }
                    assistantMsg.rendered = true;
                    this.isStreaming = false;
                    this.restoreInputFocus();
                    return;
                }

                newId = (await createRes.json()).conversation_id;
                this.conversationId = newId;

                // Step 2: subscribe BEFORE dispatching the job so the broadcasts
                // emitted during the streaming job land on a live channel. The
                // row exists at this point so channel auth will succeed.
                await this.subscribeToConversation(newId);

                // Step 3: update the URL so a reload keeps the conversation.
                const url = new URL(window.location.href);
                url.pathname = url.pathname.replace(/\/chats\/?$/, `/chats/${newId}`);
                url.search = '';
                url.hash = '';
                history.replaceState(null, '', url.toString());

                window.dispatchEvent(new CustomEvent('chat:conversation-created', {
                    detail: { id: newId },
                }));

                // Step 4: trigger the AI by hitting the existing send endpoint.
                // It reserves a credit, dispatches ProcessChatMessage, and the
                // job's broadcasts arrive on our already-subscribed channel.
                this.startStreamTimeout();
                this.scrollToBottom(true);

                this.streamAbortController = new AbortController();

                const sendRes = await fetch(sendUrl.replace(/\/$/, '') + '/' + newId, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        document: payload,
                        conversation_id: newId,
                        model: this.selectedModel,
                    }),
                    signal: this.streamAbortController.signal,
                });

                if (!sendRes.ok) {
                    const body = await sendRes.json().catch(() => ({}));

                    if (sendRes.status === 429 && body?.error === 'rate_limited') {
                        this.handleSendRateLimit(body, payload);
                        return;
                    }

                    const assistantMsg = this.messages[this.messages.length - 1];

                    if (sendRes.status === 402 && body?.error === 'credits_exhausted') {
                        const resetLabel = body.reset_at ? new Date(body.reset_at).toLocaleDateString() : null;
                        assistantMsg.paywall = {
                            heading: "You've used all your AI credits",
                            body: resetLabel ? `Your plan resets on ${resetLabel}.` : 'Add credits to keep chatting.',
                            upgrade_url: body.upgrade_url || '/app',
                        };
                        assistantMsg.content = '';
                    } else {
                        assistantMsg.content = body?.message || `Error ${sendRes.status}: ${sendRes.statusText}`;
                    }
                    assistantMsg.rendered = true;
                    this.isStreaming = false;
                    this.clearStreamTimeout();
                    this.restoreInputFocus();
                    return;
                }
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
                const assistantMsg = this.messages[this.messages.length - 1];
                assistantMsg.content = 'Network error. Please try again.';
                assistantMsg.rendered = true;
                this.isStreaming = false;
                this.clearStreamTimeout();
                this.restoreInputFocus();
            }

            return;
        }

        if (!this.channel) {
            await this.subscribeToConversation(this.conversationId);
        } else if (this.channel.readyPromise) {
            await this.channel.readyPromise;
        }

        const nowIso = new Date().toISOString();
        this.messages.push(this.ensureClientKey({ role: 'user', content: text, document: payload, editing: false, editText: '', copiedAt: 0, created_at: nowIso }));
        this.localEditor()?.clear();
        this.input = '';
        this.currentToolStatus = null;

        this.mintAssistantStub();

        const url = this.conversationId
            ? sendUrl.replace(/\/$/, '') + '/' + this.conversationId
            : sendUrl;

        this.startStreamTimeout();

        this.streamAbortController = new AbortController();

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': window.document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    document: payload,
                    conversation_id: this.conversationId,
                    model: this.selectedModel,
                }),
                signal: this.streamAbortController.signal,
            });

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));

                if (response.status === 429 && body?.error === 'rate_limited') {
                    this.handleSendRateLimit(body, payload);
                    return;
                }

                const assistantMsg = this.messages[this.messages.length - 1];

                if (response.status === 401 || response.status === 419) {
                    try { localStorage.setItem('chat:draft', text); } catch (_) { /* ignore */ }
                    assistantMsg.content = 'Your session expired. Please sign in again — your message is saved locally.';
                    assistantMsg.sessionExpired = true;
                    assistantMsg.rendered = true;
                    this.isStreaming = false;
                    this.clearStreamTimeout();
                    this.restoreInputFocus();
                    return;
                }

                if (response.status === 402 && body?.error === 'credits_exhausted') {
                    const resetLabel = body.reset_at ? new Date(body.reset_at).toLocaleDateString() : null;
                    assistantMsg.paywall = {
                        heading: "You've used all your AI credits",
                        body: resetLabel ? `Your plan resets on ${resetLabel}.` : 'Add credits to keep chatting.',
                        upgrade_url: body.upgrade_url || '/app',
                    };
                    assistantMsg.content = '';
                } else {
                    assistantMsg.content = body.message || `Error ${response.status}: ${response.statusText}`;
                }

                assistantMsg.rendered = true;
                this.isStreaming = false;
                this.clearStreamTimeout();
                this.restoreInputFocus();
                return;
            }

            const body = await response.json();
            if (body.conversation_id && body.conversation_id !== this.conversationId) {
                this.conversationId = body.conversation_id;
                this.subscribeToConversation(body.conversation_id);
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            const assistantMsg = this.messages[this.messages.length - 1];
            assistantMsg.content = 'Network error. Please try again.';
            assistantMsg.rendered = true;
            this.isStreaming = false;
            this.clearStreamTimeout();
            this.restoreInputFocus();
        }

        this.scrollToBottom(true);
    },

    async cancelStream() {
        if (this.conversationId) {
            try {
                await fetch(@js(url('/chat/conversations')) + '/' + this.conversationId + '/cancel', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                });
            } catch (_) { /* best-effort */ }
        }

        try { this.streamAbortController?.abort(); } catch (_) { /* ignore */ }
        this.streamAbortController = null;

        this.unsubscribe();
        this.clearStreamTimeout();

        const assistantMsg = this.messages[this.messages.length - 1];
        if (assistantMsg?.role === 'assistant') {
            if (!assistantMsg.content) {
                assistantMsg.content = 'Cancelled.';
            }
            assistantMsg.rendered = true;
            assistantMsg.prerendered = false;
        }

        this.currentToolStatus = null;
        this.isStreaming = false;
        this.queuedSend = null;
        this.restoreInputFocus();
    },

    async retryTurn(msg) {
        if (this.isStreaming) return;
        if (msg._retrying) return;

        // Failed user turn: the server never stored the message — re-send the
        // preceding user message from local state (same flow as edit-resend).
        // Compute userIndex BEFORE committing to any state change so the no-op
        // path never sets _retrying.
        const idx = this.messages.indexOf(msg);
        let userIndex = -1;
        for (let i = idx - 1; i >= 0; i--) {
            if (this.messages[i].role === 'user') { userIndex = i; break; }
        }
        if (userIndex === -1) return;

        const userText = this.messages[userIndex].content;
        // Splice exactly the user message at userIndex plus the failed assistant
        // bubble (msg) when it sits directly after — no further messages removed.
        const removeCount = (this.messages[userIndex + 1] === msg) ? 2 : 1;
        this.messages.splice(userIndex, removeCount);
        this.input = userText;
        this.localEditor()?.setText(userText);
        this.$nextTick(() => this.sendMessage());
    },

    handleStreamStart(event) {
        this.startStreamTimeout();
        this.targetBubbleFor(event.invocation_id ?? null);
    },

    handleTextDelta(event) {
        this.startStreamTimeout();
        this.currentToolStatus = null;
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        let delta = event.delta || '';

        if (assistantMsg._needsSeparator && delta && !/^\s/.test(delta)) {
            delta = ' ' + delta;
            assistantMsg._needsSeparator = false;
        }

        assistantMsg.content += delta;
        this.scrollToBottom();
    },

    handleToolCall(event) {
        this.startStreamTimeout();
        this.currentToolStatus = this.friendlyToolStatus(event?.tool_name);
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        if (assistantMsg.content && !/\s$/.test(assistantMsg.content)) {
            assistantMsg._needsSeparator = true;
        }
        this.scrollToBottom();
    },

    handleToolResult(event) {
        this.startStreamTimeout();
        this.currentToolStatus = null;
        const assistantMsg = this.targetBubbleFor(event.invocation_id ?? null);
        if (assistantMsg.content && !/\s$/.test(assistantMsg.content)) {
            assistantMsg._needsSeparator = true;
        }
        if (!event.result) return;
        try {
            const result = typeof event.result === 'string' ? JSON.parse(event.result) : event.result;
            if (result.type !== 'pending_action') return;
            // A retried job re-emits the same proposal (server collapses it to the
            // same id) — rendering it twice would show two identical cards.
            const seen = this.messages.some((m) =>
                (m.pending_actions || []).some((a) => a.pending_action_id === result.pending_action_id));
            if (seen) return;
            result.status = 'pending';
            assistantMsg.pending_actions.push(result);
            this.scrollToBottom();
        } catch { /* not pending action JSON */ }
    },

    // Reconcile a bubble against persisted state: pull the authoritative text
    // (missed deltas) AND merge any still-pending proposal cards the client
    // never received. Targets the bubble whose stream just ended when known;
    // falls back to the last assistant bubble (watchdog path).
    async reconcileLatestAssistant(target = null) {
        const assistantMsg = target ?? this.lastAssistantBubble();
        if (assistantMsg?.role !== 'assistant') return;
        try {
            const authoritative = await this.$wire.latestAssistantMessage();
            if (!authoritative) return;
            // Capture the persisted id even when the text already matches —
            // feedback (thumbs) and supersede anchoring need it.
            if (authoritative.id && !assistantMsg.id) {
                assistantMsg.id = authoritative.id;
            }
            const isUnstartedStub = assistantMsg.invocationId == null && !assistantMsg.content && !assistantMsg.rendered;
            if (authoritative.content && authoritative.content !== assistantMsg.content && !isUnstartedStub) {
                assistantMsg.content = authoritative.content;
                assistantMsg.id = authoritative.id;
                assistantMsg.rendered = false;
                assistantMsg.prerendered = false;
            }
            if (!Array.isArray(assistantMsg.pending_actions)) assistantMsg.pending_actions = [];
            // Span ALL bubbles: a card already rendered in an earlier bubble must
            // not be merged again into this one (it would show twice).
            const have = new Set(this.messages.flatMap((m) =>
                (m.pending_actions || []).map((a) => a.pending_action_id)));
            for (const card of (authoritative.pending_actions || [])) {
                if (!have.has(card.pending_action_id)) assistantMsg.pending_actions.push(card);
            }
        } catch (e) {
            // Non-fatal: keep the streamed content if reconciliation fails.
        }
    },

    async handleStreamEnd(event) {
        this.currentToolStatus = null;
        const inv = event?.invocation_id ?? null;
        let assistantMsg = null;
        if (inv !== null) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === inv) { assistantMsg = m; break; }
            }
        }
        if (!assistantMsg) {
            assistantMsg = this.lastAssistantBubble();
            // Never finalize an unstarted continuation stub minted AFTER the
            // ended stream — the ended turn is the assistant bubble before it.
            if (assistantMsg && assistantMsg.invocationId == null && !assistantMsg.content && !assistantMsg.rendered) {
                const idx = this.messages.indexOf(assistantMsg);
                for (let i = idx - 1; i >= 0; i--) {
                    const m = this.messages[i];
                    if (m.role === 'assistant') { assistantMsg = m; break; }
                }
            }
        }
        await this.reconcileLatestAssistant(assistantMsg);
        if (assistantMsg?.role === 'assistant') {
            assistantMsg.rendered = true;
            assistantMsg.prerendered = false;
        }
        // A completed turn means the conversation recovered — failure banners on
        // earlier bubbles describe a state that no longer exists (and reload
        // would drop them anyway, since failed turns are never persisted).
        this.messages.forEach((m) => {
            if (m.role === 'assistant' && m.streamError) {
                m.streamError = null;
                m.retryable = false;
            }
        });
        this.isStreaming = false;
        this.clearStreamTimeout();
        this.scrollToBottom();
        this.restoreInputFocus();
        this.flushQueuedSend();
        this.maybeSyncTitle();
    },

    // A brand-new chat is auto-titled server-side after its first turn, but the
    // Filament page header (H1 + tab title) was rendered at mount and still reads
    // "New chat". Pull the freshly generated title and reuse the existing
    // chat:renamed handler to update the header without a reload.
    async maybeSyncTitle() {
        if (!this.conversationId) return;
        if (!document.title.startsWith('New chat')) return;
        try {
            const title = await this.$wire.conversationTitle(this.conversationId);
            if (title) {
                window.dispatchEvent(new CustomEvent('chat:renamed', {
                    detail: { conversationId: this.conversationId, title },
                }));
            }
        } catch (_) { /* non-fatal: header just stays generic until reload */ }
    },

    // The send hit the per-plan throttle. Undo the optimistic bubbles, put the
    // text back in the composer, and count down to an automatic re-send. The
    // user keeps everything they typed; nothing fake lands in the transcript.
    handleSendRateLimit(body, payload) {
        const stub = this.messages[this.messages.length - 1];
        if (stub?.role === 'assistant' && !stub.content && !stub.rendered && !(stub.pending_actions || []).length) {
            this.messages.pop();
        }
        const userMsg = this.messages[this.messages.length - 1];
        if (userMsg?.role === 'user' && !userMsg.editing) {
            this.messages.pop();
        }

        this.localEditor()?.setDocument?.(payload);

        this.isStreaming = false;
        this.clearStreamTimeout();
        // +1s margin: re-sending at the exact Retry-After boundary lands back
        // in the closing window and 429s again (observed live).
        this.startRateLimitCountdown(Math.max(2, (Number(body?.retry_after_seconds) || 30) + 1), payload);
        this.restoreInputFocus();
    },

    startRateLimitCountdown(seconds, document = null) {
        this.clearRateLimit();
        this.rateLimit = { secondsLeft: seconds, timerId: null, document };
        this.rateLimit.timerId = setInterval(() => {
            if (!this.rateLimit) return;
            this.rateLimit.secondsLeft -= 1;
            if (this.rateLimit.secondsLeft > 0) return;
            const doc = this.plainDocument(this.rateLimit.document);
            this.clearRateLimit();
            if (doc) {
                this.localEditor()?.setDocument?.(doc);
            }
            // setTimeout, NOT $nextTick: an exception in any effect sharing
            // Alpine's flush queue (e.g. the banner tearing down) would drop a
            // queued nextTick callback — observed live. A macrotask is isolated.
            setTimeout(() => this.sendMessage(), 50);
        }, 1000);
    },

    clearRateLimit() {
        if (this.rateLimit?.timerId) {
            clearInterval(this.rateLimit.timerId);
        }
        this.rateLimit = null;
    },

    flushQueuedSend() {
        if (!this.queuedSend) return;
        const queued = this.queuedSend;
        this.queuedSend = null;
        if (queued.model && this.modelOptions.some((o) => o.value === queued.model)) {
            this.selectedModel = queued.model;
        }
        this.$nextTick(() => {
            this.localEditor()?.setDocument?.(this.plainDocument(queued.document));
            this.sendMessage();
        });
    },

    handleStreamFailed(event) {
        this.currentToolStatus = null;
        // Prefer the bubble that is actually mid-stream (unrendered). The last
        // bubble can be a fresh continuation stub minted after the failing
        // turn — painting the error there would mislabel a different turn.
        let b = null;
        for (let i = this.messages.length - 1; i >= 0; i--) {
            const m = this.messages[i];
            if (m.role === 'assistant' && !m.rendered) { b = m; break; }
        }
        if (!b) b = this.lastAssistantBubble();
        if (b && !b.rendered) {
            b.content = '';
            b.invocationId = null;
            b.streamError = event?.message || 'The assistant encountered an error. Please try again.';
            b.retryable = true;
            b.rendered = true;
            b.prerendered = false;
        }
        this.isStreaming = false;
        const queued = this.queuedSend;
        this.queuedSend = null;
        if (queued) {
            this.$nextTick(() => this.localEditor()?.setDocument?.(this.plainDocument(queued.document)));
        }
        this.clearStreamTimeout();
        this.restoreInputFocus();
    },

    // The job hit a provider 429/529 and will re-stream this turn from the top
    // after `delaySeconds`. Pre-clear the partial text (the re-stream replays it)
    // and tell the user what's happening instead of going silent.
    // Ghost-guard: if there is no unrendered bubble and we are not streaming, this
    // event is a stale broadcast from a previous turn — ignore it entirely.
    handleStreamRetrying(event) {
        // When an invocation_id is present, target the bubble for that specific
        // invocation (handles approve-mid-stream where the last bubble may be a
        // freshly-minted continuation stub, not the one that's retrying).
        // Fall back to lastAssistantBubble() when no id is available.
        let b = null;
        if (event?.invocation_id) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const m = this.messages[i];
                if (m.role === 'assistant' && m.invocationId === event.invocation_id) {
                    b = m;
                    break;
                }
            }
        }
        if (!b) {
            b = this.lastAssistantBubble();
        }
        if ((!b || b.rendered) && !this.isStreaming) return;
        if (b && !b.rendered) {
            b.content = '';
            // Do NOT null invocationId — the re-stream reuses the same invocation.
            b._needsSeparator = false;
        }
        this.isStreaming = true;
        this.currentToolStatus = `Provider is busy — retrying (attempt ${event?.attempt ?? '?'} of ${event?.maxAttempts ?? 5})…`;
        this.startStreamTimeout(((event?.delaySeconds ?? 0) * 1000) + this.streamTimeoutMs);
    },

    restoreInputFocus() {
        this.$nextTick(() => {
            if (this.messages.some((m) => m.editing)) return;
            this.localEditor()?.focus();
        });
    },

    handleConversationResolved(event) {
        if (!event?.conversationId) return;
        if (!this.conversationId) {
            this.conversationId = event.conversationId;
        }
    },

    // The transcript renders batch cards with per-item Created/Skipped chips.
    // applyProposalResolution() (the docked card's resolution bridge) writes
    // action.itemResults; the _proposal-card partial reads them through this
    // getter in both its compact-while-pending and full resolved modes.
    itemResult(action, index) {
        return (action.itemResults && action.itemResults[index]) || null;
    },

    // Past-tense verb for a resolved item's chip, by operation.
    itemVerb(action) {
        const op = action?.operation;
        return op === 'delete' ? 'Deleted' : (op === 'update' ? 'Updated' : 'Created');
    },

    // Reload-safe agent outcome summary for a finalized proposal. Built purely from
    // the persisted action (status, itemResults, record refs, display) so it survives
    // a conversation reload exactly like the audit card — no stored message and no AI
    // continuation (both intentionally removed). Returns null while still pending.
    proposalOutcome(action) {
        if (!action || action.status === 'pending') return null;

        const op = action.operation;
        const verb = op === 'delete' ? 'Deleted' : (op === 'update' ? 'Updated' : 'Created');
        const items = action.display?.items;

        if (Array.isArray(items) && items.length > 0) {
            const created = [];
            const skipped = [];
            items.forEach((item, i) => {
                const res = this.itemResult(action, i) || this.itemResult(action, String(i));
                if (!res) return;
                const name = res.record?.label || this.proposalItemName(item) || 'record';
                if (res.status === 'approved') created.push(name);
                else if (res.status === 'skipped') skipped.push(name);
            });
            const skippedVerb = op === 'delete' ? 'kept' : 'skipped';
            const parts = [];
            if (created.length) parts.push(`${verb} ${this.joinNames(created)}`);
            if (skipped.length) parts.push(`${skippedVerb} ${this.joinNames(skipped)}`);
            if (parts.length === 0) return null;
            const sentence = parts.join('; ') + '.';
            return sentence.charAt(0).toUpperCase() + sentence.slice(1);
        }

        if (action.status === 'approved') {
            const label = action.record?.label || this.extractQuotedName(action.display?.summary) || 'the record';
            return `${verb} ${label}.`;
        }
        if (action.status === 'rejected') {
            const label = this.extractQuotedName(action.display?.summary);
            if (op === 'delete') return label ? `Kept ${label} — deletion discarded.` : 'Deletion discarded.';
            return label ? `Discarded ${label}.` : 'Proposal discarded.';
        }
        return null;
    },

    proposalItemName(item) {
        if (!item) return null;
        const fields = item.fields;
        if (Array.isArray(fields) && fields.length > 0) {
            const value = fields[0].value ?? fields[0].new;
            if (typeof value === 'string' && value !== '') return value;
        }
        return this.extractQuotedName(item.summary);
    },

    extractQuotedName(text) {
        if (typeof text !== 'string') return null;
        const match = text.match(/"([^"]+)"/);
        return match ? match[1] : null;
    },

    joinNames(names) {
        const list = names.filter(Boolean);
        if (list.length === 0) return '';
        if (list.length === 1) return list[0];
        if (list.length === 2) return `${list[0]} and ${list[1]}`;
        return `${list.slice(0, -1).join(', ')}, and ${list[list.length - 1]}`;
    },

    // The user owns the scroll position. Streaming autoscrolls ONLY while they
    // are already pinned near the bottom; once they scroll up to read, new
    // content raises the "Jump to latest" pill instead of yanking them down.
    // force=true is for actions the user just took themselves (sending, the
    // pill, initial load).
    scrollToBottom(force = false) {
        if (!force && !this.pinnedToBottom) {
            this.hasUnseenBelow = true;
            return;
        }
        this.$nextTick(() => {
            const el = this.$refs.messages;
            if (el) el.scrollTop = el.scrollHeight;
            this.pinnedToBottom = true;
            this.hasUnseenBelow = false;
        });
    },

    trackScrollPosition() {
        const el = this.$refs.messages;
        if (!el) return;
        this.pinnedToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
        if (this.pinnedToBottom) {
            this.hasUnseenBelow = false;
        }
    },

    jumpToLatest() {
        this.scrollToBottom(true);
    },

}));
</script>
@endscript
