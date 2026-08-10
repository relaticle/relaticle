<div
    x-data="{}"
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
            @class([
                'fixed bottom-0 right-6 z-40 flex flex-col overflow-hidden rounded-t-xl border border-b-0 border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-900',
                'w-[36rem] h-[34rem]' => ! $isMinimized,
                'w-72 h-11' => $isMinimized,
            ])
        >
            {{-- Title bar --}}
            <div class="flex h-11 shrink-0 items-center justify-between border-b border-gray-200 px-4 dark:border-gray-700">
                <button type="button" wire:click="restore" class="flex items-center gap-2 truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                    <x-heroicon-o-envelope class="h-4 w-4 shrink-0 text-gray-400" />
                    <span class="truncate">{{ filled($subject) ? $subject : __('filament/emails/composer.title') }}</span>
                </button>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" wire:click="{{ $isMinimized ? 'restore' : 'minimize' }}" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800">
                        <x-heroicon-o-minus class="h-4 w-4" />
                    </button>
                    <button type="button" wire:click="close" class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800">
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                    </button>
                </div>
            </div>

            @unless ($isMinimized)
                {{-- Field rows --}}
                <div class="shrink-0 divide-y divide-gray-100 px-4 text-sm dark:divide-gray-800">
                    @if (count($this->accountOptions) > 1)
                        <label class="flex items-center gap-3 py-1.5">
                            <span class="w-12 shrink-0 text-gray-400">{{ __('filament/emails/composer.fields.from') }}</span>
                            <select wire:model="accountId" class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0">
                                @foreach ($this->accountOptions as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    @error('accountId') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror

                    <div class="flex items-center gap-3 py-1.5">
                        <span class="w-12 shrink-0 text-gray-400">{{ __('filament/emails/composer.fields.to') }}</span>
                        <div class="min-w-0 flex-1">
                            <x-emails.recipient-chips wire:model="to" :suggestions="$this->recipientSuggestions" />
                        </div>
                        <span class="shrink-0 space-x-2 text-xs text-gray-400">
                            <button type="button" wire:click="toggleCc" class="hover:text-gray-600">{{ __('filament/emails/composer.fields.cc') }}</button>
                            <button type="button" wire:click="toggleBcc" class="hover:text-gray-600">{{ __('filament/emails/composer.fields.bcc') }}</button>
                        </span>
                    </div>
                    @error('to') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    {{-- The `to.*` => email rule keys its errors per array index (to.0, to.1, ...),
                         not the bare `to` key, so it needs its own wildcard @error block. --}}
                    @error('to.*') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror

                    @if ($showCc)
                        <div class="flex items-center gap-3 py-1.5">
                            <span class="w-12 shrink-0 text-gray-400">{{ __('filament/emails/composer.fields.cc') }}</span>
                            <div class="min-w-0 flex-1"><x-emails.recipient-chips wire:model="cc" :suggestions="$this->recipientSuggestions" /></div>
                        </div>
                        @error('cc.*') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    @endif

                    @if ($showBcc)
                        <div class="flex items-center gap-3 py-1.5">
                            <span class="w-12 shrink-0 text-gray-400">{{ __('filament/emails/composer.fields.bcc') }}</span>
                            <div class="min-w-0 flex-1"><x-emails.recipient-chips wire:model="bcc" :suggestions="$this->recipientSuggestions" /></div>
                        </div>
                        @error('bcc.*') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                    @endif

                    <div class="flex items-center gap-3 py-1.5">
                        <span class="w-12 shrink-0 text-gray-400">{{ __('filament/emails/composer.fields.subject') }}</span>
                        <input type="text" wire:model="subject" class="w-full border-0 bg-transparent p-0 text-sm focus:ring-0" />
                    </div>
                    @error('subject') <p class="pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                </div>

                {{-- Body: Filament RichEditor with floating toolbar only --}}
                <div class="min-h-0 flex-1 overflow-y-auto px-4 py-2" wire:ignore>
                    {{ $this->getSchema('bodySchema') }}
                </div>
                @error('bodyHtml') <p class="px-4 pb-1 text-xs text-danger-600">{{ $message }}</p> @enderror

                {{-- Bottom bar --}}
                <div class="flex h-12 shrink-0 items-center justify-between border-t border-gray-200 px-3 dark:border-gray-700">
                    <div class="flex items-center gap-1">
                        <x-emails.composer-icon-button icon="heroicon-o-paper-clip" :label="__('filament/emails/composer.actions.attach')" x-on:click="$refs.attachments.click()" />
                        <input type="file" x-ref="attachments" wire:model="attachments" multiple class="hidden" />
                        <x-emails.composer-signature-menu :options="$this->signatureOptions" :selected="$signatureId" />
                    </div>
                    <x-filament::button wire:click="send" wire:loading.attr="disabled" icon="heroicon-o-paper-airplane" size="sm">
                        {{ __('filament/emails/composer.actions.send') }}
                    </x-filament::button>
                </div>
            @endunless
        </div>
    @endif
</div>
