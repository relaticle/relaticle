@props(['search', 'folder'])

<div class="flex flex-col items-center justify-center gap-2 px-4 py-16 text-center">
    @if (filled($search))
        <x-heroicon-o-magnifying-glass class="h-8 w-8 text-gray-300 dark:text-gray-600" />
        <p class="text-sm text-gray-400 dark:text-gray-500">No results for "{{ $search }}"</p>
    @else
        <x-heroicon-o-envelope class="h-8 w-8 text-gray-300 dark:text-gray-600" />
        <p class="text-sm text-gray-400 dark:text-gray-500">
            @if ($folder->value === 'all')
                No emails
            @elseif ($folder->value === 'sent')
                No sent emails
            @else
                No received emails
            @endif
        </p>
    @endif
</div>
