@php
    /** @var list<array{id: string, name: string, url: string, value: string}> $duplicates */
    $mergeAction = $getAction('mergeDuplicate');
@endphp

<ul class="grid gap-1.5">
    @foreach ($duplicates as $duplicate)
        <li wire:key="duplicate-{{ $duplicate['id'] }}" class="flex items-center gap-2.5 rounded-lg py-2 ps-2.5 pe-2 ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="min-w-0 flex-1">
                <a href="{{ $duplicate['url'] }}" wire:navigate class="flex min-w-0 items-center gap-2 text-sm font-medium text-gray-950 hover:underline dark:text-white">
                    <span class="flex size-5 shrink-0 items-center justify-center rounded bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                        <x-filament::icon icon="lucide-copy" class="size-3" />
                    </span>
                    <span class="truncate">{{ $duplicate['name'] }}</span>
                </a>
                <span class="block truncate ps-7 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('filament/record-page.merge.duplicate.shares', ['value' => $duplicate['value']]) }}
                </span>
            </div>

            {{ $mergeAction(['duplicate' => $duplicate['id']]) }}
        </li>
    @endforeach
</ul>
