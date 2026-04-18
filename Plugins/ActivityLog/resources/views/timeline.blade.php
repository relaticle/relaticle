<div class="flex flex-col gap-6">
    @if ($paginator->total() === 0)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyState }}</p>
    @else
        <ol class="relative before:absolute before:inset-y-4 before:left-[138px] before:w-px before:bg-gray-200 dark:before:bg-white/10">
            @foreach ($paginator->items() as $entry)
                <li class="relative py-3">
                    {!! $registry->resolve($entry)->render($entry) !!}
                </li>
            @endforeach
        </ol>

        @if ($paginator->hasPages())
            <div class="pt-2">
                {{ $paginator->links() }}
            </div>
        @endif
    @endif
</div>
