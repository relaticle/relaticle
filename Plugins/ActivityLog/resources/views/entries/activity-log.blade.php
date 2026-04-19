@php
    use Relaticle\ActivityLog\Support\ActivityLogOperation;
    use Relaticle\ActivityLog\Support\ActivityLogSummary;

    $summary = ActivityLogSummary::from($entry);
    $icon = $summary->operation?->icon() ?? 'ri-edit-line';
@endphp

<div
    x-data="{ open: false }"
    data-type="{{ $entry->type }}"
    data-event="{{ $entry->event }}"
    @class([
        'group grid grid-cols-[28px_1fr_auto] items-start gap-x-3 rounded-md px-2 py-2 transition',
        'cursor-pointer hover:bg-gray-50/60 dark:hover:bg-white/[0.03]' => $summary->hasDiff,
    ])
    @if ($summary->hasDiff)
        role="button"
        tabindex="0"
        :aria-expanded="open.toString()"
        @click="open = !open"
        @keydown.enter.prevent="open = !open"
        @keydown.space.prevent="open = !open"
    @endif
>
    <div class="flex justify-center pt-0.5">
        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
            <x-filament::icon :icon="$icon" class="h-3.5 w-3.5" />
        </span>
    </div>

    <div class="min-w-0">
        <p class="text-[13px] leading-5 text-gray-700 dark:text-gray-300">
            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $summary->causerName }}</span>
            @if ($summary->operation === ActivityLogOperation::Updated)
                <span>{{ __('activity-log::messages.entry.changed') }}</span>
                @if (count($summary->changedFieldLabels) === 1)
                    <span class="font-medium text-gray-900 underline decoration-dotted decoration-gray-400 underline-offset-[3px] dark:text-gray-100">{{ $summary->changedFieldLabels[0] }}</span>
                @elseif (count($summary->changedFieldLabels) >= 2)
                    <span class="font-medium text-gray-900 underline decoration-dotted decoration-gray-400 underline-offset-[3px] dark:text-gray-100">{{ __('activity-log::messages.entry.attributes', ['count' => count($summary->changedFieldLabels)]) }}</span>
                @else
                    <span>{{ $entry->subject?->getAttribute('name') ?? __('activity-log::messages.summary.this_record') }}</span>
                @endif
            @else
                {{ Str::of($summary->summarySentence)->after($summary->causerName)->trim() }}
            @endif
        </p>

        @if ($summary->hasDiff)
            <div x-show="open" x-cloak x-collapse class="mt-2">
                <dl class="divide-y divide-gray-100 rounded-md border border-gray-200 bg-gray-50/50 text-[12px] dark:divide-white/5 dark:border-white/10 dark:bg-white/[0.02]">
                    @foreach ($summary->diffRows as $row)
                        <div class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)] items-start gap-x-3 px-3 py-2">
                            <dt class="truncate pt-[1px] text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $row->label }}
                            </dt>
                            <dd class="flex flex-wrap items-center gap-2 text-gray-700 dark:text-gray-300">
                                <span
                                    class="line-clamp-2 text-gray-500 line-through decoration-gray-400/50 dark:text-gray-500"
                                    title="{{ $row->formattedOld() }}"
                                >
                                    {{ $row->formattedOld() }}
                                </span>
                                <x-filament::icon icon="ri-arrow-right-line" class="h-3 w-3 shrink-0 text-gray-400" />
                                <span
                                    class="line-clamp-2 font-medium text-gray-900 dark:text-gray-100"
                                    title="{{ $row->formattedNew() }}"
                                >
                                    {{ $row->formattedNew() }}
                                </span>
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>

    <div class="flex items-center gap-1.5 pt-0.5">
        <time
            class="text-[11px] text-gray-500 dark:text-gray-400 tabular-nums"
            datetime="{{ $entry->occurredAt->toIso8601String() }}"
            title="{{ $entry->occurredAt->toDayDateTimeString() }}"
        >
            {{ $entry->occurredAt->diffForHumans(syntax: null, short: true) }}
        </time>
        @if ($summary->hasDiff)
            <x-filament::icon
                icon="ri-arrow-down-s-line"
                class="h-4 w-4 text-gray-400 transition-transform"
                x-bind:class="open ? 'rotate-180' : ''"
                aria-hidden="true"
            />
        @endif
    </div>
</div>
