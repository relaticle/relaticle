@php
    $percent = $this->syncDisplayPercent();
    $emailsProcessed = $this->syncEmailsProcessed();
    $meetingsProcessed = $this->syncMeetingsProcessed();
    $showsMeetingsProcessed = $this->syncShowsMeetingsProcessed();
    $isInitialImport = $this->syncIsInitialImport();
    $showsPercent = $this->syncShowsPercent();
    $showsProcessedCounts = $this->syncShowsProcessedCounts();
@endphp

<div
    @if ($this->shouldPollMailboxSync()) wire:poll.5s="refreshMailboxSync" @endif
    data-testid="meetings-mailbox-sync"
    class="rounded-xl border border-dashed border-[var(--surface-block-border)] px-6 py-10 text-center"
    aria-busy="true"
    aria-live="polite"
>
    <p class="text-sm font-medium text-gray-900 dark:text-white">
        @if ($showsPercent)
            {{ __('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => $percent]) }}
        @else
            {{ __('filament/pages/dashboard.meetings.syncing.title') }}
        @endif
    </p>

    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        @if ($isInitialImport)
            {{ __('filament/pages/dashboard.meetings.syncing.description_initial') }}
        @else
            {{ __('filament/pages/dashboard.meetings.syncing.description_update') }}
        @endif
    </p>

    @if ($showsProcessedCounts)
        <div class="mt-3 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
            @if ($emailsProcessed > 0)
                <p>
                    @if ($isInitialImport)
                        {{ trans_choice('filament/pages/dashboard.meetings.syncing.emails_processed', $emailsProcessed, ['count' => $emailsProcessed]) }}
                    @else
                        {{ trans_choice('filament/pages/dashboard.meetings.syncing.emails_updated', $emailsProcessed, ['count' => $emailsProcessed]) }}
                    @endif
                </p>
            @endif
            @if ($showsMeetingsProcessed && $meetingsProcessed > 0)
                <p>
                    @if ($isInitialImport)
                        {{ trans_choice('filament/pages/dashboard.meetings.syncing.meetings_processed', $meetingsProcessed, ['count' => $meetingsProcessed]) }}
                    @else
                        {{ trans_choice('filament/pages/dashboard.meetings.syncing.meetings_updated', $meetingsProcessed, ['count' => $meetingsProcessed]) }}
                    @endif
                </p>
            @endif
        </div>
    @endif

    <div
        class="mx-auto mt-6 h-1 max-w-xs overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
        role="progressbar"
        @if ($showsPercent)
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-label="{{ __('filament/pages/dashboard.meetings.syncing.title_with_percent', ['percent' => $percent]) }}"
        @else
            aria-label="{{ __('filament/pages/dashboard.meetings.syncing.title') }}"
        @endif
        aria-busy="true"
    >
        <div
            @class([
                'h-full rounded-full bg-primary-600',
                'transition-[width] duration-500 ease-out' => $showsPercent,
                'w-1/3 animate-pulse' => ! $showsPercent,
            ])
            @if ($showsPercent)
                style="width: {{ $percent }}%"
            @endif
        ></div>
    </div>
</div>
