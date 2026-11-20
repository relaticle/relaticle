@use(Illuminate\Support\Number)
<div class="space-y-8">
    <div>
        <p class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-display text-5xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ Number::format($total) }}</span>
            <span class="text-sm text-gray-600 dark:text-gray-400">{{ __('cloud::billing.usage_tab.used_since', ['date' => $since]) }}</span>
        </p>
    </div>

    @if($total === 0)
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('cloud::billing.usage_tab.empty', ['date' => $since]) }}</p>
    @else
        <section x-data="{ hover: null }" aria-labelledby="credit-usage-chart">
            <h3 id="credit-usage-chart" class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('cloud::billing.usage_tab.chart') }}</h3>

            <div class="relative mt-4 flex gap-3" aria-hidden="true">
                <div class="flex h-40 w-10 shrink-0 flex-col justify-between text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">
                    <span class="-translate-y-1/2">{{ Number::format($ceiling) }}</span>
                    <span>{{ Number::format(intdiv($ceiling, 2)) }}</span>
                    <span class="translate-y-1/2">0</span>
                </div>

                <div class="relative h-40 flex-1">
                    <div class="absolute inset-x-0 top-0 border-t border-gray-200 dark:border-white/10"></div>
                    <div class="absolute inset-x-0 top-1/2 border-t border-gray-200 dark:border-white/10"></div>
                    <div class="absolute inset-x-0 bottom-0 border-t border-gray-300 dark:border-white/20"></div>

                    <div class="relative flex h-full items-end gap-0.5">
                        @foreach($days as $index => $day)
                            <div class="relative flex h-full flex-1 items-end justify-center" x-on:mouseenter="hover = {{ $index }}" x-on:mouseleave="hover = null">
                                @if($day['credits'] > 0)
                                    <div class="w-full max-w-6 rounded-t bg-primary-500 transition-opacity dark:bg-primary-400" :class="hover !== null && hover !== {{ $index }} && 'opacity-60'" style="height: {{ round($day['credits'] / $ceiling * 100, 2) }}%"></div>
                                @endif
                                <div x-show="hover === {{ $index }}" x-cloak class="pointer-events-none absolute bottom-full z-10 mb-2 whitespace-nowrap rounded-lg bg-gray-950 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-white dark:text-gray-950">
                                    <span class="font-semibold tabular-nums">{{ Number::format($day['credits']) }}</span>
                                    <span class="text-gray-300 dark:text-gray-600">{{ $day['label'] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-2 flex justify-between pl-[3.25rem] text-xs text-gray-500 dark:text-gray-400" aria-hidden="true">
                <span>{{ $days[0]['label'] }}</span>
                @if(count($days) > 1)
                    <span>{{ $days[array_key_last($days)]['label'] }}</span>
                @endif
            </div>

            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">{{ __('cloud::billing.usage_tab.show_table') }}</summary>
                <table class="mt-3 w-full max-w-sm text-left">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr><th class="py-1 font-medium">{{ __('cloud::billing.usage_tab.day') }}</th><th class="py-1 text-right font-medium">{{ __('cloud::billing.usage_tab.credits') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($days as $day)
                            <tr aria-label="{{ __('cloud::billing.usage_tab.bar', ['date' => $day['label'], 'credits' => Number::format($day['credits'])]) }}">
                                <td class="py-1.5 text-gray-700 dark:text-gray-300">{{ $day['label'] }}</td>
                                <td class="py-1.5 text-right tabular-nums text-gray-950 dark:text-white">{{ Number::format($day['credits']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        @if($members !== [] || $models !== [])
            <div class="grid gap-8 sm:grid-cols-2">
                @foreach(['by_member' => $members, 'by_model' => $models] as $heading => $rows)
                    @if($rows !== [])
                        <section>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __("cloud::billing.usage_tab.{$heading}") }}</h3>
                            <ul class="mt-3 space-y-3">
                                @foreach($rows as $row)
                                    <li>
                                        <div class="flex items-baseline justify-between gap-3 text-sm">
                                            <span class="truncate text-gray-700 dark:text-gray-300">{{ $row['name'] }}</span>
                                            <span class="tabular-nums font-medium text-gray-950 dark:text-white">{{ Number::format($row['credits']) }}</span>
                                        </div>
                                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-primary-100 dark:bg-primary-400/15">
                                            <div class="h-full rounded-full bg-primary-500 dark:bg-primary-400" style="width: {{ round($row['credits'] / max(1, $rows[0]['credits']) * 100, 2) }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
            </div>
        @endif
    @endif
</div>
