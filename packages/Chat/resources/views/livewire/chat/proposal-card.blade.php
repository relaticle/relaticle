<div>
    @if ($proposal)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $proposal->display_data['title'] ?? '' }}</div>
            <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $proposal->display_data['summary'] ?? '' }}</div>
            <div class="mt-2 space-y-1">
                @foreach (($proposal->display_data['fields'] ?? []) as $row)
                    <div class="flex items-start gap-2 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ $row['label'] ?? '' }}</span>
                        <span class="text-gray-900 dark:text-white">{{ $row['value'] ?? '' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
