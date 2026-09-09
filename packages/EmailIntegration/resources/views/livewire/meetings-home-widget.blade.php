<div class="mt-14" data-testid="meetings-home">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ __('filament/pages/dashboard.meetings.heading') }}
        </h2>
        <div class="flex shrink-0 items-center gap-1">
            <span class="px-1 text-xs text-gray-500 dark:text-gray-400">{{ $this->dateLabel() }}</span>
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
        </div>
    </div>

    @if ($this->meetings->isEmpty())
        <div class="rounded-xl border border-dashed border-[var(--surface-block-border)] px-6 py-10 text-center">
            <p class="text-sm font-medium text-gray-900 dark:text-white">
                {{ __('filament/pages/dashboard.meetings.empty.title') }}
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('filament/pages/dashboard.meetings.empty.description') }}
            </p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($this->meetings as $meeting)
                @php
                    $attendeeLimit = 3;
                    $attendeeCount = $meeting->attendees->count();
                    $attendeeStates = $this->attendeeState($meeting, $attendeeLimit);
                    $dotColor = $this->viewerResponseColor($meeting);
                    $expanded = in_array($meeting->getKey(), $this->expandedMeetingIds, true);
                    $openTarget = "openMeeting('{$meeting->getKey()}')";
                @endphp
                <article
                    wire:key="meeting-home-{{ $meeting->getKey() }}"
                    data-testid="meeting-card"
                    class="rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] px-4 py-3"
                >
                    <div class="flex items-center gap-2">
                        <span
                            @class([
                                'size-2 shrink-0 rounded-full',
                                'bg-success-500' => $dotColor === 'success',
                                'bg-danger-500' => $dotColor === 'danger',
                                'bg-warning-500' => $dotColor === 'warning',
                                'bg-gray-400' => $dotColor === 'gray',
                            ])
                            aria-hidden="true"
                        ></span>

                        <div class="min-w-0 flex-1">
                            <button
                                type="button"
                                data-testid="open-meeting"
                                wire:click="{{ $openTarget }}"
                                wire:loading.attr="disabled"
                                wire:target="{{ $openTarget }}"
                                class="group inline-flex min-h-11 max-w-full items-center gap-1.5 rounded-md text-left transition disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
                            >
                                <span class="min-w-0 truncate text-sm font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white">
                                    {{ $meeting->title }}
                                </span>
                                <x-filament::icon
                                    :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowsPointingOut"
                                    class="size-3.5 shrink-0 text-gray-400 group-hover:text-primary-600 dark:text-gray-500"
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

                    <div class="mt-1">
                        <div class="mb-1 flex items-center gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ __('filament/resources/meeting.sections.participants.heading') }}
                            </span>
                            <span class="rounded-full bg-gray-100 px-1.5 text-pico text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                {{ $attendeeCount }}
                            </span>
                        </div>

                        @forelse ($attendeeStates as $state)
                            @include('email-integration::filament.infolists.partials.meeting-attendee-row', ['state' => $state])
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('filament/resources/meeting.sections.participants.empty') }}
                            </p>
                        @endforelse

                        @if ($attendeeCount > $attendeeLimit)
                            <button
                                type="button"
                                wire:click="toggleAttendees('{{ $meeting->getKey() }}')"
                                class="mt-2 inline-flex min-h-11 items-center gap-1 text-xs text-gray-500 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:text-gray-400 dark:hover:text-white"
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
