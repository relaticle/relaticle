{{-- Static twin of the empty message box (packages/Chat/resources/views/livewire/chat/partials/_composer-bar.blade.php):
     attach on the lead side, the model picker, and the mic, which fills with the primary color while the box is empty. --}}
<div {{ $attributes->class('rounded-2xl border border-[var(--surface-input-border)] bg-white dark:bg-gray-900') }} aria-hidden="true">
    <p class="min-h-16 px-4 pb-2 pt-3 text-sm leading-6 text-gray-400 dark:text-gray-500">{{ __('Ask anything...') }}</p>

    <div class="flex items-center gap-2 px-3 pb-2">
        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-gray-500 dark:text-gray-400">
            <x-lucide-paperclip class="h-4 w-4"/>
        </span>

        <span class="ms-auto flex items-center gap-1.5">
            <span class="inline-flex h-7 items-center gap-1 rounded-md border border-transparent px-2 text-xs font-medium text-gray-600 dark:text-gray-300">
                {{ __('Auto') }}
                <x-lucide-chevrons-up-down class="h-3 w-3"/>
            </span>
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white">
                <x-lucide-mic class="h-4 w-4"/>
            </span>
        </span>
    </div>
</div>
