<div
    x-data="{
        insertVariable(id) {
            const editor = document.querySelector('.email-composer-body [x-data^=\'richEditorFormComponent\']')

            if (editor) {
                Alpine.$data(editor).insertMergeTag(id)
            }
        },
    }"
    x-on:keydown.window="(() => {
        if ($event.key === 'c'
            && ! $event.metaKey && ! $event.ctrlKey && ! $event.altKey
            && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName)
            && ! $event.target.isContentEditable
        ) { $event.preventDefault(); $wire.dispatch('composer:open') }
    })()"
>
    @if ($isOpen)
        <div
            x-data
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            @class([
                'fixed z-40 flex flex-col overflow-hidden rounded-xl bg-white shadow-2xl shadow-gray-950/20 ring-1 ring-gray-950/10 transition-all duration-200 dark:bg-gray-900 dark:shadow-black/50 dark:ring-white/10',
                'bottom-4 right-4' => $isMinimized || ! $isExpanded,
                'w-[38rem] h-[36rem]' => ! $isMinimized && ! $isExpanded,
                'w-80' => $isMinimized,
                'inset-4 md:inset-8' => ! $isMinimized && $isExpanded,
            ])
        >
            {{-- Title bar --}}
            <div
                @class([
                    'flex h-12 shrink-0 items-center justify-between gap-2 bg-gray-50 pl-4 pr-2 dark:bg-white/5',
                    'border-b border-gray-200 dark:border-white/10' => ! $isMinimized,
                ])
            >
                <button
                    type="button"
                    wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}"
                    class="flex min-w-0 flex-1 items-center gap-2 text-left text-sm font-medium text-gray-900 dark:text-gray-100"
                >
                    <x-heroicon-m-envelope class="h-4 w-4 shrink-0 text-primary-500" />
                    <span class="truncate">{{ filled($subject) ? $subject : __('filament/emails/composer.title') }}</span>
                </button>

                <div class="flex shrink-0 items-center gap-0.5">
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
                    <button
                        type="button"
                        wire:click="close"
                        aria-label="{{ __('filament/emails/composer.actions.close') }}"
                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-400/10 dark:hover:text-danger-400"
                    >
                        <x-heroicon-m-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>

            @unless ($isMinimized)
                {{-- Field rows --}}
                <div class="shrink-0 divide-y divide-gray-100 px-4 text-sm dark:divide-white/5">
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
                <p class="shrink-0 px-4 pt-3 text-xs font-medium uppercase tracking-wide text-gray-400">
                    {{ __('filament/emails/composer.fields.message') }}
                </p>
                <div class="email-composer-body-ctn min-h-0 flex-1 overflow-y-auto px-4 pb-3 pt-1" wire:ignore>
                    {{ $this->getSchema('bodySchema') }}
                </div>
                @error('bodyHtml') <p class="px-4 pb-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror

                {{-- Attachments --}}
                @if ($attachments !== [])
                    <div class="flex shrink-0 flex-wrap gap-1.5 border-t border-gray-100 px-4 py-2 dark:border-white/5">
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
                <div class="flex h-14 shrink-0 items-center justify-between border-t border-gray-200 px-3 dark:border-white/10">
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
                        />
                        <x-emails.composer-picker-menu
                            icon="heroicon-o-document-text"
                            :label="__('filament/emails/composer.actions.template')"
                            :options="$this->templateOptions"
                            :empty-label="__('filament/emails/composer.fields.template_none')"
                            :click="fn (?string $id): string => 'applyTemplate(\''.$id.'\')'"
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
</div>
