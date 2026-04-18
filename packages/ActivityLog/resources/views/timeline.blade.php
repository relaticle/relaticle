<div class="space-y-4">
    @if ($paginator->total() === 0)
        <p class="text-sm text-gray-500">{{ $emptyState }}</p>
    @else
        @if ($grouped !== null)
            @foreach ($grouped as $bucket => $entries)
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                        {{ __('activity-log::timeline.groups.'.$bucket) }}
                    </h3>
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($entries as $entry)
                            {!! $registry->resolve($entry)->render($entry) !!}
                        @endforeach
                    </div>
                </section>
            @endforeach
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($paginator->items() as $entry)
                    {!! $registry->resolve($entry)->render($entry) !!}
                @endforeach
            </div>
        @endif

        <div class="pt-2">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
