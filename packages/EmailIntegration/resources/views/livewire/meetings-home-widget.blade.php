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
                    $participants = $card['participants'];
                    $time = $card['time'];
                    $happeningNow = $card['happening_now'];
                    $meetingKey = $card['id'];
                    $openTarget = "openMeeting('{$meetingKey}')";
                    $participantsId = "meeting-home-participants-{$meetingKey}";
                    $hasParticipants = $participants['attendees'] !== [];
                    $expandLabel = __('filament/pages/dashboard.meetings.expand_participants', ['title' => $card['title']]);
                    $collapseLabel = __('filament/pages/dashboard.meetings.collapse_participants', ['title' => $card['title']]);
                    $timeIcon = $card['all_day']
                        ? \Filament\Support\Icons\Heroicon::OutlinedCalendarDays
                        : \Filament\Support\Icons\Heroicon::OutlinedClock;
                @endphp
                <div
                    wire:key="meeting-home-{{ $meetingKey }}"
                    x-data="{ expanded: false, expandLabel: @js($expandLabel), collapseLabel: @js($collapseLabel) }"
                    data-testid="meeting-card"
                    x-bind:class="expanded && '-mx-1 my-1 rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] px-4 py-3 shadow-sm'"
                >
                    <div class="flex min-h-11 items-center gap-3 py-3" x-bind:class="expanded && 'py-0'">
                        <span
                            class="size-1.5 shrink-0 rounded-full bg-primary-500"
                            aria-hidden="true"
                        ></span>

                        <div
                            class="min-w-0 flex-1 truncate text-sm font-medium text-gray-950 dark:text-white"
                            data-testid="meeting-card-title"
                        >
                            {{ $card['title'] }}
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

                        <button
                            type="button"
                            data-testid="meeting-card-open"
                            wire:click="{{ $openTarget }}"
                            wire:loading.attr="disabled"
                            wire:target="{{ $openTarget }}"
                            class="inline-flex shrink-0 items-center justify-center rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-wait disabled:opacity-60 dark:hover:bg-white/10 dark:hover:text-gray-300"
                            aria-label="{{ __('filament/pages/dashboard.meetings.open_named', ['title' => $card['title']]) }}"
                        >
                            <x-filament::icon
                                :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass"
                                class="size-4 shrink-0"
                            />
                        </button>

                        @if ($hasParticipants)
                            <button
                                type="button"
                                data-testid="meeting-card-toggle"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-md p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-white/10 dark:hover:text-gray-300"
                                x-on:click="expanded = ! expanded"
                                x-bind:aria-expanded="expanded.toString()"
                                aria-expanded="false"
                                aria-controls="{{ $participantsId }}"
                                aria-label="{{ $expandLabel }}"
                                x-bind:aria-label="expanded ? collapseLabel : expandLabel"
                            >
                                @if ($participants['avatars'] !== [])
                                    <span class="flex -space-x-1.5" aria-hidden="true">
                                        @foreach ($participants['avatars'] as $avatar)
                                            @include('email-integration::filament.infolists.partials.meeting-attendee-avatar', [
                                                'src' => $avatar['src'],
                                                'alt' => $avatar['alt'],
                                                'hasName' => $avatar['has_name'],
                                                'size' => 'sm',
                                                'class' => 'ring-2 ring-[var(--surface-block-bg)]',
                                            ])
                                        @endforeach
                                    </span>
                                @endif

                                @if ($participants['overflow'] > 0)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('filament/pages/dashboard.meetings.more_participants', ['count' => $participants['overflow']]) }}
                                    </span>
                                @endif

                                <span
                                    class="inline-flex transition-transform duration-200 motion-reduce:transition-none"
                                    x-bind:class="expanded && 'rotate-180'"
                                >
                                    <x-filament::icon
                                        :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronDown"
                                        class="size-4 shrink-0"
                                    />
                                </span>
                            </button>
                        @endif
                    </div>

                    @if ($hasParticipants)
                        <div
                            id="{{ $participantsId }}"
                            data-testid="meeting-card-participants"
                            role="region"
                            x-cloak
                            x-show="expanded"
                            x-collapse
                            class="mt-3 border-t border-[var(--surface-block-border)] pt-2"
                        >
                            @foreach ($participants['attendees'] as $state)
                                @include('email-integration::filament.infolists.partials.meeting-attendee-row', [
                                    'state' => $state,
                                    'showEmail' => false,
                                ])
                            @endforeach
                        </div>
                    @endif
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
