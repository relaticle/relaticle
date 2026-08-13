<div
    @if ($dock === 'inline')
        {{-- The reader closes by removing this component along with itself, which is
             too late for an event-based "save your draft first". The reader finds the
             dock by this hook and awaits its save before unmounting. --}}
        data-inline-composer
    @endif
    @class([
        // Inline: the draft is appended to the reading pane's scroll region, so it is
        // simply as tall as its message — the region does the scrolling.
        'shrink-0' => $dock === 'inline',
    ])
    x-data="{
        insertVariable(id) {
            const editor = document.querySelector('.email-composer-body [x-data^=\'richEditorFormComponent\']')

            if (editor) {
                Alpine.$data(editor).insertMergeTag(id)
            }
        },
    }"
    @if ($dock === 'floating')
        x-on:keydown.window="(() => {
            if ($event.key === 'c'
                && ! $event.metaKey && ! $event.ctrlKey && ! $event.altKey
                && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)
                && ! $event.target.isContentEditable
            ) { $event.preventDefault(); $wire.dispatch('composer:open') }
        })()"
    @endif
>
    @php
        // Composing and editing a saved draft both open fitted to the screen, so a
        // draft looks the same wherever it came from — and the same as the reader it
        // sits alongside. Shrinking drops back to the corner window.
        $isModal = $dock === 'floating' && $isExpanded && ! $isMinimized;
    @endphp

    @if ($isOpen)
        @if ($isModal)
            {{-- Backdrop click minimises rather than closes: the corner window keeps the
                 draft in progress and in sight, where closing would file it away. --}}
            <div
                wire:click="minimize"
                class="fi-email-reader-backdrop fixed inset-0 z-40 bg-gray-950/50"
            ></div>
        @endif

        <div
            x-data
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            @class([
                'flex flex-col bg-white dark:bg-gray-900',
                'overflow-hidden' => $dock === 'floating',
                // Docked under the message being answered. No height of its own and no
                // internal scroll: it grows with the message, and the region scrolls.
                '' => $dock === 'inline',
                'fixed z-40 rounded-xl shadow-2xl shadow-gray-950/20 ring-1 ring-gray-950/10 transition-all duration-200 dark:shadow-black/50 dark:ring-white/10' => $dock === 'floating',
                'bottom-4 right-4' => $dock === 'floating' && ($isMinimized || ! $isExpanded),
                'w-[38rem] h-[36rem]' => $dock === 'floating' && ! $isMinimized && ! $isExpanded,
                'w-80' => $dock === 'floating' && $isMinimized,
                // Matches the reader's panel: centred, same width and height.
                'fi-email-reader-panel left-1/2 top-1/2 z-50 h-[85vh] w-[calc(100%-2rem)] max-w-5xl -translate-x-1/2 -translate-y-1/2' => $isModal,
            ])
        >
            {{-- Title bar. The inline dock sits inside the message it answers, so it
                 needs no window chrome — just a Draft marker and a way out. --}}
            <div
                @class([
                    'flex shrink-0 items-center justify-between gap-2',
                    'sticky top-0 z-10 h-10 border-t-2 border-dashed border-gray-200 bg-white px-4 dark:border-gray-700 dark:bg-gray-900 sm:px-6' => $dock === 'inline',
                    'h-12 bg-gray-50 pl-4 pr-2 dark:bg-white/5' => $dock === 'floating',
                    'border-b border-gray-200 dark:border-white/10' => $dock === 'floating' && ! $isMinimized,
                ])
            >
                @if ($dock === 'inline')
                    <span class="text-sm font-medium text-primary-600 dark:text-primary-400">
                        {{ __('filament/emails/composer.draft') }}
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                        class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium text-gray-900 dark:text-gray-100"
                    >
                        <x-heroicon-m-envelope class="h-4 w-4 shrink-0 text-primary-500" />
                        <span class="truncate">{{ filled($subject) ? $subject : __('filament/emails/composer.title') }}</span>
                    </button>
                @endif

                <div class="flex shrink-0 items-center gap-0.5">
                    @if ($dock === 'floating')
                        <button
                            type="button"
                            wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                            aria-label="{{ $isMinimized ? __('filament/emails/composer.actions.restore') : __('filament/emails/composer.actions.minimize') }}"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-200/70 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
                        >
                            <x-dynamic-component :component="$isMinimized ? 'heroicon-m-chevron-up' : 'heroicon-m-minus'" class="h-4 w-4" />
                        </button>
                        @unless ($isMinimized)
                            <button
                                type="button"
                                wire:click="toggleExpand"
                                aria-label="{{ $isExpanded ? __('filament/emails/composer.actions.shrink') : __('filament/emails/composer.actions.expand') }}"
                                class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-200/70 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            >
                                <x-dynamic-component
                                    :component="$isExpanded ? 'heroicon-m-arrows-pointing-in' : 'heroicon-m-arrows-pointing-out'"
                                    class="h-4 w-4"
                                />
                            </button>
                        @endunless
                    @endif
                    {{-- Every × that sits on a Draft bar throws the draft away, including
                         a row a previous save already wrote — on the dock this bar IS
                         that Draft bar. Closing the floating window's chrome instead
                         puts the draft away and keeps it; so does closing the reader. --}}
                    <button
                        type="button"
                        wire:click="{{ $dock === 'inline' ? 'discard' : 'close' }}"
                        aria-label="{{ $dock === 'inline' ? __('filament/emails/composer.actions.discard') : __('filament/emails/composer.actions.close') }}"
                        @if ($dock === 'inline')
                            x-tooltip="{ content: @js(__('filament/emails/composer.actions.discard')), theme: $store.theme }"
                        @endif
                        @class([
                            'rounded-lg p-1.5 text-gray-400 transition',
                            'hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10 dark:hover:text-danger-400' => $dock === 'inline',
                            'hover:bg-gray-200/70 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200' => $dock === 'floating',
                        ])
                    >
                        <x-heroicon-m-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>

            @unless ($isMinimized)
                {{-- The message being answered or forwarded, above the draft and split
                     off by the same dashed rule the inline dock uses — so a reply looks
                     the same whether it is being written under the original or reopened
                     later from Drafts. The dock never shows this: there, the real
                     message is already on screen right above it. --}}
                @if ($isModal && $this->sourceEmail !== null)
                    <div class="min-h-0 flex-1 overflow-y-auto border-b-2 border-dashed border-gray-200 dark:border-gray-700">
                        <x-emails.quoted-message :record="$this->sourceEmail" />
                    </div>

                    {{-- Same Draft bar the inline dock carries. Its × throws the draft
                         away — including the row a previous save already wrote — which
                         is the one place that deletes. The window × above keeps it. --}}
                    <div class="flex h-10 shrink-0 items-center justify-between gap-2 px-4">
                        <span class="text-sm font-medium text-primary-600 dark:text-primary-400">
                            {{ __('filament/emails/composer.draft') }}
                        </span>
                        <button
                            type="button"
                            wire:click="discard"
                            aria-label="{{ __('filament/emails/composer.actions.discard') }}"
                            x-tooltip="{ content: @js(__('filament/emails/composer.actions.discard')), theme: $store.theme }"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10 dark:hover:text-danger-400"
                        >
                            <x-heroicon-m-x-mark class="h-4 w-4" />
                        </button>
                    </div>
                @endif

                {{-- Field rows --}}
                <div @class(['shrink-0 divide-y divide-gray-100 text-sm dark:divide-white/5', 'px-4' => $dock === 'floating', 'px-4 sm:px-6' => $dock === 'inline'])>
                    <label class="flex items-center gap-3 py-2">
                        <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('filament/emails/composer.fields.from') }}</span>
                        <span class="flex min-w-0 flex-1 items-center gap-2">
                            <x-filament::avatar
                                :src="$this->fromAvatarUrl"
                                :alt="$this->fromAccount?->label ?? ''"
                                size="h-6 w-6"
                                class="shrink-0"
                            />
                            @if (count($this->accountOptions) > 1)
                                <select wire:model.live="accountId" class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 focus:ring-0 dark:text-gray-100">
                                    @foreach ($this->accountOptions as $id => $label)
                                        <option value="{{ $id }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="truncate text-sm text-gray-900 dark:text-gray-100">{{ $this->fromAccount?->label }}</span>
                            @endif
                        </span>
                    </label>
                    @error('accountId') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror

                    <div class="flex items-center gap-3 py-2">
                        <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('filament/emails/composer.fields.to') }}</span>
                        <div class="min-w-0 flex-1">
                            <x-emails.recipient-chips wire:model="to" :suggestions="$this->recipientSuggestions" />
                        </div>
                        <span class="shrink-0 space-x-2 text-xs font-medium text-gray-400">
                            <button type="button" wire:click="toggleCc" @class(['transition hover:text-gray-700 dark:hover:text-gray-200', 'text-primary-600 dark:text-primary-400' => $showCc])>{{ __('filament/emails/composer.fields.cc') }}</button>
                            <button type="button" wire:click="toggleBcc" @class(['transition hover:text-gray-700 dark:hover:text-gray-200', 'text-primary-600 dark:text-primary-400' => $showBcc])>{{ __('filament/emails/composer.fields.bcc') }}</button>
                        </span>
                    </div>
                    @error('to') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                    {{-- The `to.*` => email rule keys its errors per array index (to.0, to.1, ...),
                         not the bare `to` key, so it needs its own wildcard @error block. --}}
                    @error('to.*') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror

                    @if ($showCc)
                        <div class="flex items-center gap-3 py-2">
                            <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('filament/emails/composer.fields.cc') }}</span>
                            <div class="min-w-0 flex-1"><x-emails.recipient-chips wire:model="cc" :suggestions="$this->recipientSuggestions" /></div>
                        </div>
                        @error('cc.*') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                    @endif

                    @if ($showBcc)
                        <div class="flex items-center gap-3 py-2">
                            <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('filament/emails/composer.fields.bcc') }}</span>
                            <div class="min-w-0 flex-1"><x-emails.recipient-chips wire:model="bcc" :suggestions="$this->recipientSuggestions" /></div>
                        </div>
                        @error('bcc.*') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                    @endif

                    <div class="flex items-center gap-3 py-2">
                        <span class="w-14 shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('filament/emails/composer.fields.subject') }}</span>
                        <input
                            type="text"
                            wire:model="subject"
                            placeholder="{{ __('filament/emails/composer.fields.subject_placeholder') }}"
                            class="w-full border-0 bg-transparent p-0 text-sm text-gray-900 placeholder:text-gray-400 focus:ring-0 dark:text-gray-100"
                        />
                    </div>
                    @error('subject') <p class="pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
                </div>

                {{-- Body: Filament RichEditor with floating toolbar only --}}
                <p @class(['shrink-0 pt-3 text-xs font-medium uppercase tracking-wide text-gray-400', 'px-4' => $dock === 'floating', 'px-4 sm:px-6' => $dock === 'inline'])>
                    {{ __('filament/emails/composer.fields.message') }}
                </p>
                <div @class([
                    'email-composer-body-ctn pt-1',
                    'min-h-0 flex-1 overflow-y-auto px-4 pb-3' => $dock === 'floating',
                    'shrink-0 px-4 pb-2 sm:px-6' => $dock === 'inline',
                ]) @if ($dock === 'inline') data-composer-dock="inline" @endif wire:ignore>
                    {{ $this->getSchema('bodySchema') }}
                </div>
                @error('bodyHtml') <p class="px-4 pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror

                {{-- Attachments: files already saved on the draft, then pending uploads --}}
                @if ($attachments !== [] || $savedAttachments !== [])
                    <div class="flex shrink-0 flex-wrap gap-1.5 border-t border-gray-100 px-4 py-2 dark:border-white/5">
                        @foreach ($savedAttachments as $saved)
                            <span wire:key="saved-attachment-{{ $saved['id'] }}" class="inline-flex max-w-[18rem] items-center gap-1.5 rounded-lg bg-gray-100 py-1 pl-2 pr-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                <x-heroicon-m-paper-clip class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                <span class="truncate">{{ $saved['filename'] }}</span>
                                <span class="shrink-0 tabular-nums text-gray-400">{{ \Illuminate\Support\Number::fileSize($saved['size'], precision: 1) }}</span>
                                <button
                                    type="button"
                                    wire:click="removeSavedAttachment('{{ $saved['id'] }}')"
                                    aria-label="{{ __('filament/emails/composer.actions.remove_attachment') }}"
                                    class="shrink-0 rounded p-0.5 text-gray-400 transition hover:text-danger-600 dark:hover:text-danger-400"
                                >
                                    <x-heroicon-m-x-mark class="h-3 w-3" />
                                </button>
                            </span>
                        @endforeach

                        @foreach ($attachments as $index => $attachment)
                            <span wire:key="attachment-{{ $index }}" class="inline-flex max-w-[18rem] items-center gap-1.5 rounded-lg bg-gray-100 py-1 pl-2 pr-1 text-xs text-gray-700 dark:bg-white/10 dark:text-gray-200">
                                <x-heroicon-m-paper-clip class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                                <span class="truncate">{{ $attachment->getClientOriginalName() }}</span>
                                <span class="shrink-0 tabular-nums text-gray-400">{{ \Illuminate\Support\Number::fileSize($attachment->getSize(), precision: 1) }}</span>
                                <button
                                    type="button"
                                    wire:click="removeAttachment({{ $index }})"
                                    aria-label="{{ __('filament/emails/composer.actions.remove_attachment') }}"
                                    class="shrink-0 rounded p-0.5 text-gray-400 transition hover:text-danger-600 dark:hover:text-danger-400"
                                >
                                    <x-heroicon-m-x-mark class="h-3 w-3" />
                                </button>
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Bottom bar --}}
                <div @class([
                    'flex h-14 shrink-0 items-center justify-between border-t border-gray-200 dark:border-white/10',
                    'px-3' => $dock === 'floating',
                    'sticky bottom-0 z-10 bg-white px-3 dark:bg-gray-900 sm:px-5' => $dock === 'inline',
                ])>
                    <div class="flex items-center gap-1">
                        <x-emails.composer-icon-button icon="heroicon-o-paper-clip" :label="__('filament/emails/composer.actions.attach')" x-on:click="$refs.attachments.click()" />
                        <input type="file" x-ref="attachments" wire:model="attachments" multiple class="hidden" />
                        <x-emails.composer-picker-menu
                            icon="heroicon-o-pencil-square"
                            :label="__('filament/emails/composer.actions.signature')"
                            :options="$this->signatureOptions"
                            :selected="$signatureId"
                            :none-label="__('filament/emails/composer.fields.signature_none')"
                            :click="fn (?string $id): string => $id === null ? '$set(\'signatureId\', null)' : '$set(\'signatureId\', \''.$id.'\')'"
                            :create-label="__('filament/emails/composer.actions.create_signature')"
                            create-click="mountAction('createSignature')"
                        />
                        <x-emails.composer-picker-menu
                            icon="heroicon-o-document-text"
                            :label="__('filament/emails/composer.actions.template')"
                            :options="$this->templateOptions"
                            :empty-label="__('filament/emails/composer.fields.template_none')"
                            :click="fn (?string $id): string => 'applyTemplate(\''.$id.'\')'"
                            :create-label="__('filament/emails/composer.actions.create_template')"
                            create-click="mountAction('createTemplate')"
                        />
                        {{-- Inserts a merge tag into the RichEditor via its own Alpine
                             component (`insertMergeTag`), which is what the editor's
                             native `{{` autocomplete calls too. --}}
                        <x-emails.composer-picker-menu
                            icon="heroicon-o-variable"
                            handler="alpine"
                            :label="__('filament/emails/composer.actions.variable')"
                            :options="\Relaticle\EmailIntegration\Services\EmailTemplateRenderService::MERGE_TAGS"
                            :click="fn (?string $id): string => 'insertVariable(\''.$id.'\')'"
                        />
                        <span wire:loading wire:target="attachments" class="pl-1 text-xs text-gray-400">{{ __('filament/emails/composer.actions.uploading') }}</span>
                    </div>

                    <x-filament::button
                        wire:click="send"
                        wire:loading.attr="disabled"
                        wire:target="send, attachments"
                        icon="heroicon-m-paper-airplane"
                    >
                        {{ __('filament/emails/composer.actions.send') }}
                    </x-filament::button>
                </div>
            @endunless
        </div>
    @endif

    <x-filament-actions::modals />
</div>
