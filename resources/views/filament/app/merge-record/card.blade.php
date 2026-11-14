<div
    @class([
        'flex h-full min-w-0 flex-col gap-3 rounded-xl p-4 ring-1',
        'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => ! $isResult,
        'bg-primary-50 ring-primary-600/20 dark:bg-primary-400/10 dark:ring-primary-400/30' => $isResult,
    ])
    @if ($isResult) data-merge-result @endif
>
    <div class="flex items-start gap-3">
        @if ($card['logo'])
            <img src="{{ $card['logo'] }}" alt="" class="size-10 shrink-0 rounded-lg bg-white object-contain ring-1 ring-gray-950/5 dark:ring-white/10" />
        @else
            <span
                @class([
                    'flex size-10 shrink-0 items-center justify-center rounded-lg text-sm font-semibold',
                    'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $isResult,
                    'bg-primary-100 text-primary-700 dark:bg-primary-400/20 dark:text-primary-300' => $isResult,
                ])
                aria-hidden="true"
            >{{ $card['initials'] }}</span>
        @endif

        <div class="min-w-0 flex-1">
            <p class="line-clamp-2 break-words text-sm font-semibold text-gray-950 dark:text-white" data-merge-name title="{{ $card['name'] }}">{{ $card['name'] }}</p>

            @if ($isKept || $isResult)
                <p @class([
                    'mt-0.5 text-xs font-medium',
                    'text-gray-500 dark:text-gray-400' => $isKept,
                    'text-primary-700 dark:text-primary-300' => $isResult,
                ])>{{ $isResult ? __('filament/record-page.merge.result') : __('filament/record-page.merge.kept') }}</p>
            @endif
        </div>

        @if ($isClearable)
            <button
                type="button"
                x-on:click="$wire.set(@js($statePath.'.keep'), 'current', false); $wire.set(@js($statePath.'.duplicate_id'), null)"
                aria-label="{{ __('filament/record-page.merge.clear') }}"
                title="{{ __('filament/record-page.merge.clear') }}"
                class="-m-1 flex size-7 shrink-0 items-center justify-center rounded-md text-gray-400 outline-none transition hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:bg-white/10 dark:hover:text-gray-200"
            >
                <x-filament::icon icon="lucide-x" class="size-menu-icon" />
            </button>
        @endif
    </div>

    <ul class="min-w-0 space-y-0.5 text-sm text-gray-600 dark:text-gray-300" data-merge-details>
        @forelse ($card['details'] as $detail)
            <li class="truncate" title="{{ $detail }}">{{ $detail }}</li>
        @empty
            <li class="text-gray-400 dark:text-gray-500">{{ $card['emptyDetails'] }}</li>
        @endforelse
    </ul>

    <ul class="mt-auto flex flex-wrap gap-x-3 gap-y-1 border-t border-gray-950/5 pt-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400" data-merge-counts>
        @foreach ($card['counts'] as $count)
            <li class="inline-flex items-center gap-1" title="{{ trans_choice("filament/record-page.merge.counts.{$count['key']}", $count['count']) }}">
                <x-filament::icon :icon="$count['icon']" class="size-3.5" />
                <span aria-hidden="true" class="tabular-nums">{{ $count['count'] }}</span>
                <span class="sr-only">{{ trans_choice("filament/record-page.merge.counts.{$count['key']}", $count['count']) }}</span>
            </li>
        @endforeach
    </ul>
</div>
