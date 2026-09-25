{{-- Shared input bar for every composer surface (full-page chat, side panel,
     dashboard). Renders the rounded container, the TipTap mount, and the
     controls row (attachment on the lead side, char counter, model picker,
     mic and send/stop on the trailing side). The caller owns the surrounding
     `x-data="chatEditor(...)"` wrapper (and its `x-on:*` listeners +
     `data-chat-context`): this partial only needs `text`, `isStreaming`,
     `rateLimit`/`submitting` etc. to already be reachable up the Alpine scope
     chain, exactly as the editor mount below relies on `x-ref="editor"`
     resolving to that same wrapper's scope.

     $showStopButton (bool, default true): dashboard has no streaming
     concept: it navigates to the conversation page instead, so it passes
     false and the send button never hides behind a stop control.
     $sendDisabled (string, required): the Alpine boolean expression fed
     to the send button's :disabled through an escaped echo. Blade escaping
     round-trips safely here: the browser decodes `&gt;` back to `>` when it
     reads the attribute, so Alpine still gets the original expression.
     $voiceAvailable (bool, resolved below): whether to render the mic at all.
     Resolved here rather than passed in, so every surface including the
     dashboard gets the same gate without having to remember it. --}}
@php
    $showStopButton ??= true;
    $voiceAvailable = app(\Relaticle\Chat\Services\ModelRegistry::class)->voiceInputAvailable();
@endphp

<div
    data-chat-composer
    class="relative rounded-2xl border border-[var(--surface-input-border)] bg-white transition focus-within:border-primary-400 dark:bg-gray-900 dark:focus-within:border-primary-500/60"
>
    {{-- wire:ignore: TipTap mounts into this node imperatively; without it
         Livewire's morphdom wipes the editor on every chat-interface re-render
         (e.g. after the first message), leaving an empty, unusable composer. --}}
    <div x-ref="editor" class="relative" wire:ignore></div>

    <div class="flex items-center gap-2 px-3 pb-2">
        <div class="flex items-center gap-1.5">
            @php $attachTexts = [
                'wrongType' => __('Attach a CSV or TXT file.'),
                'tooLarge' => __('Files up to 10 MB.'),
                'failed' => __('Could not upload that file. Try again.'),
            ]; @endphp
            <div
                x-data="chatAttachment({
                    uploadUrl: @js(route('chat.attachments.store')),
                    deleteUrlTemplate: @js(route('chat.attachments.destroy', ['attachment' => '__ID__'])),
                    context: @js($context ?? 'conversation'),
                    maxBytes: @js(\Relaticle\Chat\Actions\StoreChatAttachment::MAX_KILOBYTES * 1024),
                    texts: @js($attachTexts),
                })"
                data-chat-attachment
                class="contents"
            >
                <input type="file" x-ref="fileInput" accept=".csv,.txt,text/csv,text/plain" class="sr-only" tabindex="-1" aria-hidden="true" x-on:change="onFileChosen($event)" data-chat-attachment-input>

                <button
                    type="button"
                    x-on:click="pick()"
                    :disabled="uploading"
                    :aria-busy="uploading"
                    aria-label="{{ __('Attach a CSV or TXT file') }}"
                    title="{{ __('Attach a CSV or TXT file') }}"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-progress dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                >
                    <x-heroicon-o-paper-clip x-show="!uploading" class="h-4 w-4" aria-hidden="true" />
                    <x-heroicon-o-arrow-path x-show="uploading" x-cloak class="h-4 w-4 motion-safe:animate-spin" aria-hidden="true" />
                </button>

                <div
                    x-show="attachment"
                    x-cloak
                    data-chat-attachment-chip
                    class="absolute bottom-full start-0 mb-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700 ring-1 ring-inset ring-gray-900/10 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10"
                >
                    <x-heroicon-m-paper-clip class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="truncate" x-text="attachment?.name"></span>
                    <span class="shrink-0 text-gray-500 dark:text-gray-400" x-text="attachment ? (attachment.row_count === 1 ? @js(__(':count row', ['count' => 1])) : @js(__(':count rows')).replace(':count', attachment.row_count)) : ''"></span>
                    <button type="button" x-on:click="remove()" class="-me-1 shrink-0 rounded p-0.5 transition hover:bg-gray-900/10 dark:hover:bg-white/20" aria-label="{{ __('Remove attachment') }}">
                        <x-heroicon-m-x-mark class="h-3 w-3" aria-hidden="true" />
                    </button>
                </div>

                <div x-show="attachError" x-cloak role="alert" class="absolute bottom-full end-0 mb-2 flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                    <span x-text="attachError"></span>
                    <button type="button" x-on:click="attachError = null" class="-me-1 shrink-0 rounded p-0.5 transition hover:bg-red-600/10 dark:hover:bg-red-400/20" aria-label="{{ __('Dismiss') }}">
                        <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </div>
            </div>
        </div>

        <div class="ms-auto flex items-center gap-1.5">
            <span
                x-show="text.length > 4000"
                x-cloak
                x-text="`${text.length.toLocaleString()} / 5,000`"
                :class="{
                    'text-gray-500 dark:text-gray-400': text.length <= 4900,
                    'text-amber-600 dark:text-amber-400': text.length > 4900 && text.length <= 5000,
                    'text-red-600 dark:text-red-400': text.length > 5000,
                }"
                class="text-[length:var(--text-micro)]"
                aria-live="polite"
            ></span>

            @include('chat::livewire.chat.partials._model-picker')

            {{-- Push-to-talk dictation. Hidden entirely, not disabled, when the
                 feature is off or the transcription provider has no key: a
                 self-hosted install without one must not see a button that
                 500s. type="button" is load-bearing: this sits inside the
                 composer's <form>, and a typeless button would submit it. --}}
            @if ($voiceAvailable)
                <div
                    x-data="voiceRecorder({
                        transcribeUrl: @js(route('chat.transcribe')),
                        unsupportedText: @js(__('Voice input is not supported in this browser.')),
                        deniedText: @js(__('Microphone access was blocked. Allow it in your browser settings.')),
                        failedText: @js(__('Could not transcribe that recording. Try again.')),
                        silentText: @js(__('No speech was detected.')),
                    })"
                    class="contents"
                >
                    {{-- Elapsed readout: the 2-minute auto-stop must not arrive
                         unannounced. tabular-nums keeps the tick from jittering. --}}
                    <span
                        x-show="recording"
                        x-cloak
                        x-text="recordingElapsed()"
                        class="text-[length:var(--text-micro)] font-medium tabular-nums text-red-600 dark:text-red-400"
                        aria-hidden="true"
                    ></span>
                    <button
                        type="button"
                        x-on:click="toggleRecording()"
                        :disabled="transcribing"
                        :aria-pressed="recording"
                        :aria-label="recording ? @js(__('Stop recording')) : @js(__('Start voice input'))"
                        :title="recording ? @js(__('Stop recording')) : @js(__('Start voice input'))"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-progress"
                        :aria-busy="transcribing"
                        :class="recording
                            ? 'bg-red-100 text-red-600 hover:bg-red-200 dark:bg-red-500/20 dark:text-red-400 dark:hover:bg-red-500/30'
                            : 'text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white'"
                    >
                        <span x-show="!transcribing" :class="recording ? 'motion-safe:animate-pulse' : ''">
                            <x-heroicon-o-microphone class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <x-heroicon-o-arrow-path x-show="transcribing" x-cloak class="h-4 w-4 motion-safe:animate-spin" aria-hidden="true" />
                    </button>

                    {{-- Floated above the bar so a failure never reflows the
                         composer. Absolute against the rounded container's
                         `relative`, opposite the attachment error's own edge. --}}
                    <div
                        x-show="voiceError"
                        x-cloak
                        role="alert"
                        class="absolute bottom-full start-0 mb-2 flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300"
                    >
                        <span x-text="voiceError"></span>
                        <button
                            type="button"
                            x-on:click="voiceError = null"
                            class="-me-1 shrink-0 rounded p-0.5 transition hover:bg-red-600/10 dark:hover:bg-red-400/20"
                            aria-label="{{ __('Dismiss') }}"
                        >
                            <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            @endif

            <button
                @if ($showStopButton) x-show="!isStreaming" x-cloak @endif
                type="submit"
                class="flex h-7 w-7 items-center justify-center rounded-full bg-primary-600 text-white transition hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:bg-gray-200 disabled:text-gray-400 dark:disabled:bg-gray-700 dark:disabled:text-gray-500"
                :disabled="{{ $sendDisabled }}"
                aria-label="{{ __('Send message') }}"
            >
                <x-heroicon-s-arrow-up class="h-4 w-4" />
            </button>

            @if ($showStopButton)
                <button
                    x-show="isStreaming"
                    x-cloak
                    type="button"
                    x-on:click="cancelStream()"
                    class="flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-white transition hover:bg-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:bg-gray-200 dark:text-gray-900 dark:hover:bg-gray-300"
                    aria-label="{{ __('Stop generation') }}"
                >
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                    </svg>
                </button>
            @endif
        </div>
    </div>
</div>
