@php
    /** @var array{start_date: string, start_time: string|null, end_time: string|null, end_date: string|null, duration: string|null, all_day: bool, datetime: string} $state */
    $state = $getState();
    $hasRange = $state['end_time'] !== null || $state['end_date'] !== null;
@endphp

<div
    class="flex items-center gap-2 border-y border-[var(--surface-block-border)] py-2.5 text-sm text-gray-600 dark:text-gray-400"
    data-testid="meeting-time-row"
>
    <x-filament::icon
        :icon="\Filament\Support\Icons\Heroicon::OutlinedClock"
        class="size-4 shrink-0 text-gray-400 dark:text-gray-500"
        aria-hidden="true"
    />

    <time
        class="flex min-w-0 flex-wrap items-center gap-x-1.5 leading-5"
        datetime="{{ $state['datetime'] }}"
    >
        <span>{{ $state['start_date'] }}</span>

        @if ($state['start_time'] !== null)
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
