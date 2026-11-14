<div @if ($token !== null) wire:poll.1500ms="check" @endif class="shrink-0">
    @if ($drafted)
        <p class="px-4 py-1 text-xs text-gray-500 dark:text-gray-400">{{ __('filament/emails/composer.rela.done') }}</p>
    @endif
    @if ($available)
        <details wire:ignore.self class="border-b border-gray-200 px-4 py-2 text-sm dark:border-white/10" x-data x-on:rela:focus.window="if ($event.detail.composerId === @js($composerId)) { $el.open = true; $nextTick(() => $refs.instruction.focus()) }">
            <summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">{{ __('filament/emails/composer.rela.title') }}</summary>
            <div class="flex items-center gap-2 py-2">
                <input x-ref="instruction" wire:model="instruction" wire:keydown.enter="request" maxlength="2000" @disabled($token !== null) aria-label="{{ __('filament/emails/composer.rela.instruction') }}" placeholder="{{ __('filament/emails/composer.rela.instruction') }}" class="min-w-0 flex-1 rounded-lg border-gray-200 text-sm dark:border-white/10 dark:bg-white/5" />
                <x-filament::button size="sm" color="gray" wire:click="request" :disabled="$token !== null">{{ __('filament/emails/composer.rela.write') }}</x-filament::button>
            </div>
            @error('instruction') <p class="text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
            @if ($state !== 'idle')
                <p role="status" class="pb-1 text-xs text-gray-500">{{ __('filament/emails/composer.rela.'.$state) }}</p>
            @endif
        </details>
    @endif
</div>
