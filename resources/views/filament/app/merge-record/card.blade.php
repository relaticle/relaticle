<div
    class="relative flex h-full min-h-56 min-w-0 flex-col overflow-hidden rounded-xl border border-gray-950/10 bg-white shadow-xs dark:border-white/10 dark:bg-gray-900"
    @if ($isResult ?? false) data-merge-result @endif
>
    @if ($isClearable)
        <button
            type="button"
            x-on:click="
                $wire.set(@js($statePath.'.keep'), 'current', false)
                $wire.set(@js($statePath.'.duplicate_id'), null).then(() => document.querySelector('[data-merge-dialog] [data-merge-picker]')?.focus())
            "
            aria-label="{{ __('filament/record-page.merge.clear') }}"
            title="{{ __('filament/record-page.merge.clear') }}"
            class="absolute end-2 top-2 flex size-7 items-center justify-center rounded-md text-gray-400 outline-none transition hover:bg-gray-950/5 hover:text-gray-600 focus-visible:outline-2 focus-visible:outline-primary-500 dark:hover:bg-white/10 dark:hover:text-gray-200"
            data-merge-clear
        >
            <x-filament::icon icon="lucide-x" class="size-menu-icon" />
        </button>
    @endif

    <div class="flex min-w-0 flex-1 flex-col items-center justify-center gap-3 p-5 text-center">
        @include('filament.app.merge-record.mark', ['logo' => $card['logo'], 'icon' => $card['icon'], 'size' => 'size-12 rounded-xl', 'iconSize' => 'size-6'])

        <p class="line-clamp-2 max-w-full break-words text-base font-semibold text-gray-950 dark:text-white" data-merge-name title="{{ $card['name'] }}">{{ $card['name'] }}</p>

        <div class="flex w-full min-w-0 items-start justify-center gap-1.5 text-sm" data-merge-details>
            <x-filament::icon :icon="$card['detailIcon']" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />

            <ul class="min-w-0 space-y-0.5 text-start">
                @forelse ($card['details'] as $detail)
                    <li class="truncate">
                        <a
                            href="{{ $detail['href'] }}"
                            @if (str_starts_with($detail['href'], 'http')) target="_blank" rel="noopener noreferrer" @endif
                            title="{{ $detail['label'] }}"
                            class="font-medium text-primary-600 hover:underline focus-visible:underline dark:text-primary-400"
                        >{{ $detail['label'] }}</a>
                    </li>
                @empty
                    <li class="text-gray-400 dark:text-gray-500">{{ $card['emptyDetails'] }}</li>
                @endforelse
            </ul>
        </div>
    </div>

    <ul class="flex w-full flex-wrap items-center justify-center gap-1.5 border-t border-gray-950/10 px-5 py-4 dark:border-white/10" data-merge-counts>
        @foreach ($card['counts'] as $count)
            @php($label = trans_choice("filament/record-page.merge.counts.{$count['key']}", $count['count']))

            <li
                x-tooltip="{ content: @js($label), theme: $store.theme }"
                class="inline-flex items-center gap-1 rounded-full border border-gray-950/10 px-2 py-0.5 text-xs text-gray-600 dark:border-white/15 dark:text-gray-300"
            >
                <x-filament::icon :icon="$count['icon']" class="size-3.5" />
                <span aria-hidden="true" class="tabular-nums">{{ $count['count'] }}</span>
                <span class="sr-only">{{ $label }}</span>
            </li>
        @endforeach
    </ul>
</div>
