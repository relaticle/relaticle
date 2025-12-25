<div class="space-y-4">
    <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-4">
        <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
            {{ $summary->summary }}
        </p>
    </div>

    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <div class="flex items-center gap-2">
            <x-heroicon-o-clock class="h-4 w-4" />
            <span>Generated {{ $summary->created_at->diffForHumans() }}</span>
        </div>
    </div>
</div>
