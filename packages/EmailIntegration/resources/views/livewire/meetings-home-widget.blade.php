@php
    $mailboxConnected = $this->isMailboxConnected();
@endphp

<div class="mt-14" data-testid="meetings-home">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/dashboard.meetings.heading') }}
        </h2>
        <div class="flex shrink-0 items-center gap-1">
            {{-- The prefix is server-rendered and the date comes from the picker's
                 own state, so a click anywhere on "Today, Sep 10" opens the calendar. --}}
            <div
                class="fi-meetings-date flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"
                x-on:click="$event.target.closest('.fi-meetings-date-trigger') || $el.querySelector('.fi-meetings-date-trigger')?.click()"
            >
                <span aria-hidden="true">{{ $this->datePrefix() }}</span>
                {{ $this->getSchema('datePickerSchema') }}
            </div>

            <x-filament::icon-button
                color="gray"
                size="sm"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronLeft"
                :label="__('filament/pages/dashboard.meetings.previous_day')"
                wire:click="previousDay"
            />
            <x-filament::icon-button
                color="gray"
                size="sm"
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

    @if (! $mailboxConnected)
        <div class="px-6 py-10 text-center" data-testid="meetings-disconnected">
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
        <div class="px-6 py-10 text-center">
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
        <div class="space-y-2">
            @foreach ($this->meetings as $meeting)
                @php
                    $attendeeLimit = 3;
                    $attendeeCount = $meeting->attendees->count();
                    $attendeeStates = $this->attendeeState($meeting, $attendeeLimit);
                    $responseStatus = $this->viewerResponseStatus($meeting);
                    $dotColor = $responseStatus->getColor();
                    $expanded = in_array($meeting->getKey(), $this->expandedMeetingIds, true);
                    $openTarget = "openMeeting('{$meeting->getKey()}')";
                @endphp
                <article
                    wire:key="meeting-home-{{ $meeting->getKey() }}"
                    data-testid="meeting-card"
                    class="group rounded-lg border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] px-3 py-2"
                >
                    <div class="flex items-center gap-2">
                        <span
                            @class([
                                'size-1.5 shrink-0 rounded-full',
                                'bg-success-500' => $dotColor === 'success',
                                'bg-danger-500' => $dotColor === 'danger',
                                'bg-warning-500' => $dotColor === 'warning',
                                'bg-gray-400' => $dotColor === 'gray',
                            ])
                            title="{{ $responseStatus->getLabel() }}"
                        ><span class="sr-only">{{ $responseStatus->getLabel() }}</span></span>

                        <div class="min-w-0 flex-1">
                            <button
                                type="button"
                                data-testid="open-meeting"
                                wire:click="{{ $openTarget }}"
                                wire:loading.attr="disabled"
                                wire:target="{{ $openTarget }}"
                                class="group/title inline-flex max-w-full items-center gap-1.5 rounded-md py-0.5 text-left transition disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                            >
                                <span class="min-w-0 truncate text-sm font-medium text-gray-950 group-hover:text-primary-600 dark:text-white">
                                    {{ $meeting->title }}
                                </span>
                                <x-filament::icon
                                    :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowsPointingOut"
                                    class="size-3.5 shrink-0 text-gray-400 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible/title:opacity-100 motion-reduce:transition-none dark:text-gray-500"
                                />
                                <span class="sr-only">{{ __('filament/pages/dashboard.meetings.open') }}</span>
                            </button>
                        </div>

                        <time
                            class="shrink-0 text-xs text-gray-500 dark:text-gray-400"
                            datetime="{{ $meeting->starts_at->timezone($this->viewerTimezone())->toIso8601String() }}"
                        >
                            @if ($meeting->all_day)
                                {{ __('filament/pages/dashboard.meetings.all_day') }}
                            @else
                                {{ $meeting->starts_at->timezone($this->viewerTimezone())->format('g:i A') }}
                            @endif
                        </time>
                    </div>

                    <div class="mt-1.5">
                        @forelse ($attendeeStates as $state)
                            @include('email-integration::filament.infolists.partials.meeting-attendee-row', ['state' => $state, 'compact' => true])
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('filament/resources/meeting.sections.participants.empty') }}
                            </p>
                        @endforelse

                        @if ($attendeeCount > $attendeeLimit)
                            <button
                                type="button"
                                wire:click="toggleAttendees('{{ $meeting->getKey() }}')"
                                class="mt-1 inline-flex items-center gap-1 py-0.5 text-xs text-gray-500 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:text-white"
                            >
                                {{ $expanded
                                    ? __('filament/resources/meeting.attendees.show_less')
                                    : __('filament/resources/meeting.attendees.show_more') }}
                                <x-filament::icon
                                    :icon="$expanded
                                        ? \Filament\Support\Icons\Heroicon::OutlinedChevronUp
                                        : \Filament\Support\Icons\Heroicon::OutlinedChevronDown"
                                    class="size-3.5"
                                />
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    <x-filament-actions::modals />
</div>
