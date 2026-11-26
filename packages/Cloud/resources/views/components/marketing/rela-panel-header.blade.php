{{-- Static twin of the header row of Rela's side panel (packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php). --}}
<div {{ $attributes->class('flex items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700') }} aria-hidden="true">
    <span class="flex min-w-0 items-center gap-2">
        <x-lucide-messages-square class="h-5 w-5 shrink-0 text-primary-600 dark:text-primary-400"/>
        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ config('chat.assistant_name') }}</span>
    </span>
    <span class="flex shrink-0 items-center gap-0.5 text-gray-400">
        <span class="flex h-7 w-7 items-center justify-center"><x-lucide-rotate-ccw-clock class="h-4 w-4"/></span>
        <span class="flex h-7 w-7 items-center justify-center"><x-lucide-ellipsis-vertical class="h-4 w-4"/></span>
        <span class="flex h-7 w-7 items-center justify-center"><x-lucide-x class="h-4 w-4"/></span>
    </span>
</div>
