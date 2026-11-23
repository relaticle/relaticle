{{-- Static twin of one field row on a single-record card (packages/Chat/resources/views/livewire/chat/partials/_dock-field.blade.php).
     `old` draws a select change: the struck old value, an arrow and the new value as a badge. Its edit and leave-out controls show on hover. --}}
@props(['label', 'value', 'old' => null, 'editable' => false, 'excludable' => false])

<div {{ $attributes->class('group/field flex items-start gap-3') }}>
    <span class="w-24 shrink-0 truncate py-1.5 text-xs leading-5 text-gray-500 sm:w-32 dark:text-gray-400">{{ $label }}</span>
    <span class="flex min-w-0 flex-1 items-start gap-1.5">
        <span class="min-w-0 flex-1 py-1.5 leading-5">
            @if ($old !== null)
                <span class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $old }}</span>
                    <x-lucide-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-micro font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $value }}</span>
                </span>
            @else
                <span class="min-w-0 break-words text-sm text-gray-900 dark:text-white">{{ $value }}</span>
            @endif
        </span>
        @if ($editable)
            <span class="inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 sm:opacity-0 sm:group-hover/field:opacity-100" aria-hidden="true">
                <x-lucide-square-pen class="h-3.5 w-3.5"/>
            </span>
        @endif
    </span>
    @if ($excludable)
        <span class="inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 sm:opacity-0 sm:group-hover/field:opacity-100" aria-hidden="true">
            <x-lucide-x class="h-3.5 w-3.5"/>
        </span>
    @endif
</div>
