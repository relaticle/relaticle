<div class="flex flex-col gap-6">
    @if ($entries->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyState }}</p>
    @else
        <ol class="relative before:absolute before:inset-y-4 before:left-[138px] before:w-px before:bg-gray-200 dark:before:bg-white/10">
            @foreach ($entries as $entry)
                <li wire:key="timeline-entry-{{ $entry->id }}" class="relative py-3">
                    {!! $registry->resolve($entry)->render($entry) !!}
                </li>
            @endforeach
        </ol>

        @if ($hasMore)
            @if ($infiniteScroll)
                <div
                    wire:intersect="loadMore"
                    wire:key="timeline-load-more"
                    class="flex items-center justify-center py-4 text-xs text-gray-500 dark:text-gray-400"
                >
                    <span wire:loading.remove wire:target="loadMore">Scroll to load more&hellip;</span>
                    <span wire:loading wire:target="loadMore" class="flex items-center gap-2">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Loading&hellip;
                    </span>
                </div>
            @else
                <div class="flex justify-center pt-2">
                    <x-filament::button
                        color="gray"
                        outlined
                        size="sm"
                        wire:click="loadMore"
                        wire:loading.attr="disabled"
                        wire:target="loadMore"
                        icon="heroicon-m-arrow-down"
                    >
                        <span wire:loading.remove wire:target="loadMore">Load more</span>
                        <span wire:loading wire:target="loadMore">Loading&hellip;</span>
                    </x-filament::button>
                </div>
            @endif
        @endif
    @endif
</div>
