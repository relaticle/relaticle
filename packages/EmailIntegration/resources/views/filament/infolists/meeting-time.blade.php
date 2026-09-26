@php
    /** @var array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string, location: string|null, calendar_url: string|null, calendar_label: string} $state */
    $state = $getState();
    $hasRange = $state['end_time'] !== null || $state['end_date'] !== null;
    $rowClass = 'flex items-center gap-2 py-2.5 text-sm text-gray-600 dark:text-gray-400';
    $iconClass = 'size-4 shrink-0 text-gray-400 dark:text-gray-500';
@endphp

<div
    class="border-y border-[var(--surface-block-border)]"
    data-testid="meeting-meta"
>
    <div class="{{ $rowClass }}" data-testid="meeting-time-row">
        <x-filament::icon
            :icon="\Filament\Support\Icons\Heroicon::OutlinedClock"
            class="{{ $iconClass }}"
            aria-hidden="true"
        />

        <time
            class="flex min-w-0 flex-wrap items-center gap-x-1.5 leading-5"
            datetime="{{ $state['datetime'] }}"
        >
            <span>{{ $state['start_date'] }}</span>

            @if ($state['start_time'] !== null)
                <span aria-hidden="true" class="text-gray-300 dark:text-gray-600">|</span>
                <span>{{ $state['start_time'] }}</span>
            @endif

            @if ($hasRange)
                <span aria-hidden="true" class="text-gray-400 dark:text-gray-500">→</span>
            @endif

            @if ($state['end_time'] !== null)
                <span>{{ $state['end_time'] }}</span>
            @endif

            @if ($state['duration'] !== null)
                <span class="text-gray-400 dark:text-gray-500">({{ $state['duration'] }})</span>
            @endif

            @if ($state['end_date'] !== null && $state['end_time'] !== null)
                <span aria-hidden="true" class="text-gray-300 dark:text-gray-600">|</span>
            @endif

            @if ($state['end_date'] !== null)
                <span>{{ $state['end_date'] }}</span>
            @endif

            @if ($state['all_day'])
                <span class="text-gray-400 dark:text-gray-500">({{ __('filament/resources/meeting.time.all_day') }})</span>
            @endif
        </time>
    </div>

    @if ($state['location'] !== null)
        <div
            class="{{ $rowClass }} border-t border-[var(--surface-block-border)]"
            data-testid="meeting-location-row"
        >
            <x-filament::icon
                :icon="\Filament\Support\Icons\Heroicon::OutlinedMapPin"
                class="{{ $iconClass }}"
                aria-hidden="true"
            />

            <span class="min-w-0 truncate leading-5">{{ $state['location'] }}</span>
        </div>
    @endif

    @if ($state['calendar_url'] !== null)
        <div
            class="{{ $rowClass }} border-t border-[var(--surface-block-border)]"
            data-testid="meeting-calendar-link-row"
        >
            <x-filament::icon
                :icon="\Filament\Support\Icons\Heroicon::OutlinedLink"
                class="{{ $iconClass }}"
                aria-hidden="true"
            />

            <a
                href="{{ $state['calendar_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="truncate leading-5 text-primary-600 hover:underline dark:text-primary-400"
            >
                {{ $state['calendar_label'] }}
            </a>
        </div>
    @endif
</div>
