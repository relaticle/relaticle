{{-- Static twin of the locked message box under a docked proposal
     (packages/Chat/resources/views/livewire/chat/partials/_composer.blade.php). --}}
<div {{ $attributes->class(['flex h-10 items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-3 text-xs text-gray-500 dark:border-white/15 dark:bg-white/5 dark:text-gray-400']) }} data-mock-locked>
    <x-heroicon-o-lock-closed class="h-3.5 w-3.5 shrink-0" aria-hidden="true"/>
    <span class="truncate">{{ __('Approve or discard the proposal to keep chatting') }}</span>
</div>
