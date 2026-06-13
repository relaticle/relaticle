<div class="flex items-start gap-3 px-2 py-2">
    <div class="flex w-7 shrink-0 justify-center pt-0.5">
        <div class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10">
            <x-heroicon-o-adjustments-horizontal class="size-3.5" />
        </div>
    </div>
    <div class="flex-1 text-sm text-gray-700 dark:text-gray-300">
        <div class="font-medium text-gray-900 dark:text-white">
            {{ $entry->causer?->name ?? __('Someone') }} updated custom fields
        </div>
        <ul class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
            @foreach ($changes as $change)
                <li>
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $change['label'] ?? $change['code'] ?? '' }}:</span>
                    <span class="line-through">{{ $change['old']['label'] ?? '—' }}</span>
                    <span>&rarr;</span>
                    <span>{{ $change['new']['label'] ?? '—' }}</span>
                </li>
            @endforeach
        </ul>
        <div class="mt-0.5 text-xs text-gray-400">{{ $entry->occurredAt->diffForHumans() }}</div>
    </div>
</div>
