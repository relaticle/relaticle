@php
    $mailboxConnected = $this->isMailboxConnected();
    $mailboxSyncing = $mailboxConnected && $this->isMailboxSyncing();
@endphp

<div class="mt-14" data-testid="meetings-home">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="flex items-baseline gap-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
            <span>{{ __('filament/pages/dashboard.meetings.heading') }}</span>
            @if ($mailboxConnected && ! $mailboxSyncing)
                <span class="text-gray-400 dark:text-gray-500">{{ $this->meetings->count() }}</span>
            @endif
        </h2>
        <div class="flex shrink-0 items-center gap-1">
            {{-- The prefix is server-rendered and the date comes from the picker's
                 own state, so a click anywhere on "Today, Sep 10" opens the calendar. --}}
            <div class="fi-meetings-date flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                <span
                    aria-hidden="true"
                    class="cursor-pointer"
                    x-on:click="$el.parentElement.querySelector('.fi-meetings-date-trigger')?.click()"
                >{{ $this->datePrefix() }}</span>
                {{ $this->getSchema('datePickerSchema') }}
            </div>

            <x-filament::icon-button
                color="gray"
                size="xs"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronLeft"
                :label="__('filament/pages/dashboard.meetings.previous_day')"
                wire:click="previousDay"
            />
            <x-filament::icon-button
                color="gray"
                size="xs"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronRight"
                :label="__('filament/pages/dashboard.meetings.next_day')"
                wire:click="nextDay"
            />

            <x-filament::dropdown placement="bottom-end" teleport>
                <x-slot name="trigger">
                    <x-filament::icon-button
                        color="gray"
                        size="sm"
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedEllipsisVertical"
                        :label="__('filament/pages/dashboard.meetings.more_actions')"
                    />
                </x-slot>

                <x-filament::dropdown.list>
                    <x-filament::dropdown.list.item
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedCalendarDays"
                        wire:click="goToToday"
                    >
                        {{ __('filament/pages/dashboard.meetings.go_to_today') }}
                    </x-filament::dropdown.list.item>

                    <x-filament::dropdown.list.item
                        tag="a"
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedCog6Tooth"
                        :href="\Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage::getUrl()"
                    >
                        {{ __('filament/pages/dashboard.meetings.calendar_settings') }}
                    </x-filament::dropdown.list.item>
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        </div>
    </div>

    @if ($mailboxSyncing)
        @include('email-integration::livewire.partials.mailbox-sync-status')
    @elseif (! $mailboxConnected)
        <div class="rounded-xl border border-dashed border-[var(--surface-block-border)] px-6 py-10 text-center" data-testid="meetings-disconnected">
            <p class="text-sm font-medium text-gray-900 dark:text-white">
                {{ __('filament/pages/dashboard.meetings.disconnected.title') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament/pages/dashboard.meetings.disconnected.description') }}
            </p>
            <div class="mt-4 flex justify-center">
                {{ $this->connectGmailAction }}
            </div>
        </div>
    @elseif ($this->meetings->isEmpty())
        @php
            $nextDayWithMeetings = $this->nextDayWithMeetings();
        @endphp
        <div class="rounded-xl border border-dashed border-[var(--surface-block-border)] px-6 py-10 text-center">
            <p class="text-sm font-medium text-gray-900 dark:text-white">
                {{ __('filament/pages/dashboard.meetings.empty.title') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament/pages/dashboard.meetings.empty.description') }}
            </p>
            @if ($nextDayWithMeetings !== null)
                <div class="mt-4 flex justify-center">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        outlined
                        :icon="\Filament\Support\Icons\Heroicon::OutlinedCalendarDays"
                        wire:click="goToNextDayWithMeetings"
                        wire:loading.attr="disabled"
                    >
                        {{ __('filament/pages/dashboard.meetings.empty.next_with_meetings') }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    @else
        <div class="flex flex-col divide-y divide-[var(--surface-block-border)]">
            @foreach (array_slice($this->meetingCards, 0, $this->visibleCount) as $card)
                @php
                    $responseStatus = $card['response_status'];
                    $dotColor = $responseStatus->getColor();
                    $time = $card['time'];
                    $happeningNow = $card['happening_now'];
                    $isPast = $card['is_past'];
                    $meetingKey = $card['id'];
                    $openTarget = "openMeeting('{$meetingKey}')";
                    $timeIcon = $card['all_day']
                        ? \Filament\Support\Icons\Heroicon::OutlinedCalendarDays
                        : \Filament\Support\Icons\Heroicon::OutlinedClock;
                @endphp
                <div
                    wire:key="meeting-home-{{ $meetingKey }}"
                    data-testid="meeting-card"
                    class="px-2.5 py-2"
                >
                    <div
                        data-testid="meeting-card-row"
                        class="flex min-h-9 cursor-pointer items-center gap-2.5"
                        wire:click="{{ $openTarget }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $openTarget }}"
                        aria-label="{{ __('filament/pages/dashboard.meetings.open_named', ['title' => $card['title']]) }}"
                    >
                        <span class="inline-flex size-3.5 shrink-0 items-center justify-center">
                            <span
                                wire:loading.remove
                                wire:target="{{ $openTarget }}"
                                @class([
                                    'size-1.5 rounded-full',
                                    'bg-success-500' => $dotColor === 'success',
                                    'bg-danger-500' => $dotColor === 'danger',
                                    'bg-warning-500' => $dotColor === 'warning',
                                    'bg-gray-400' => $dotColor === 'gray',
                                ])
                                aria-hidden="true"
                            ></span>
                            <x-filament::loading-indicator
                                wire:loading
                                wire:target="{{ $openTarget }}"
                                @class([
                                    'size-3.5',
                                    'text-success-500' => $dotColor === 'success',
                                    'text-danger-500' => $dotColor === 'danger',
                                    'text-warning-500' => $dotColor === 'warning',
                                    'text-gray-400' => $dotColor === 'gray',
                                ])
                                role="status"
                                aria-label="{{ __('filament/pages/dashboard.meetings.open_named', ['title' => $card['title']]) }}"
                            />
                        </span>

                        <div
                            class="group/title relative flex min-w-0 flex-1 items-center gap-1.5"
                            data-testid="meeting-card-title"
                        >
                            <span @class([
                                'min-w-0 truncate text-sm font-medium',
                                'text-gray-400 line-through dark:text-gray-500' => $isPast,
                                'text-gray-950 dark:text-white' => ! $isPast,
                            ])>
                                {{ $card['title'] }}
                            </span>

                            <button
                                type="button"
                                data-testid="meeting-card-open"
                                wire:click.stop="{{ $openTarget }}"
                                wire:loading.attr="disabled"
                                wire:target="{{ $openTarget }}"
                                x-on:click.stop
                                @class([
                                    'inline-flex shrink-0 items-center justify-center rounded-md p-0.5 opacity-0 transition-opacity focus-visible:opacity-100 group-hover/title:opacity-100',
                                    'text-success-600 hover:bg-success-50 dark:text-success-400 dark:hover:bg-success-500/10' => $dotColor === 'success',
                                    'text-danger-600 hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-500/10' => $dotColor === 'danger',
                                    'text-warning-600 hover:bg-warning-50 dark:text-warning-400 dark:hover:bg-warning-500/10' => $dotColor === 'warning',
                                    'text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/10' => $dotColor === 'gray',
                                ])
                                aria-label="{{ __('filament/pages/dashboard.meetings.open_named', ['title' => $card['title']]) }}"
                            >
                                <x-filament::icon
                                    :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowsPointingOut"
                                    class="size-3.5 shrink-0"
                                />
                            </button>
                        </div>

                        <time
                            class="inline-flex shrink-0 items-center gap-1.5 text-xs font-normal tabular-nums text-gray-500 dark:text-gray-400"
                            datetime="{{ $time['datetime'] }}"
                        >
                            <x-filament::icon
                                :icon="$timeIcon"
                                class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500"
                            />
                            <span class="whitespace-nowrap">{{ $time['range'] }}</span>
                            @if ($happeningNow)
                                <x-filament::badge color="primary" size="sm">
                                    {{ __('filament/pages/dashboard.meetings.happening_now') }}
                                </x-filament::badge>
                            @endif
                        </time>
                    </div>
                </div>
            @endforeach

            @if ($this->hasMoreMeetings())
                <button
                    type="button"
                    data-testid="meetings-load-more"
                    wire:click="loadMore"
                    wire:loading.attr="disabled"
                    class="w-full rounded-sm py-2 text-center text-xs text-gray-500 transition hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-wait dark:text-gray-400 dark:hover:text-white"
                >
                    {{ __('filament/pages/dashboard.meetings.load_more') }}
                </button>
            @endif
        </div>
    @endif

    <x-filament-actions::modals />
</div>
