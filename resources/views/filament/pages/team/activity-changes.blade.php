@php
    /** @var list<array{label: string, old: string, new: string}> $rows */
@endphp

<dl class="divide-y divide-gray-100 rounded-lg border border-gray-200 dark:divide-white/5 dark:border-white/10">
    @foreach ($rows as $row)
        <div class="grid gap-1 px-4 py-3 sm:grid-cols-[minmax(0,9rem)_minmax(0,1fr)] sm:gap-4">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $row['label'] }}
            </dt>

            <dd class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">
                    {{ $row['old'] }}
                </span>

                <x-filament::icon
                    icon="heroicon-m-arrow-right"
                    class="h-3 w-3 shrink-0 self-center text-gray-300 dark:text-gray-600"
                />

                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $row['new'] }}
                </span>
            </dd>
        </div>
    @endforeach
</dl>
