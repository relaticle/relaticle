@php
    /** @var array{name: string, email: string, avatar: string, is_organizer: bool, response_status: \Relaticle\EmailIntegration\Enums\AttendeeResponseStatus|null} $state */

    // The home card trades the resolved name for the raw address to hold the
    // row to one tight line. The slide-over has room, so it keeps the name and
    // the roomier spacing.
    $compact = $compact ?? false;
@endphp

@if ($compact)
    <div class="flex items-center gap-2 py-0.5">
        <x-filament::avatar
            :src="$state['avatar']"
            :alt="$state['name']"
            size="size-5"
            :circular="true"
            class="shrink-0"
        />

        <span class="min-w-0 flex-1 truncate text-xs text-gray-700 dark:text-gray-300">
            {{ $state['email'] !== '' ? $state['email'] : $state['name'] }}
        </span>

        @if ($state['is_organizer'])
            <x-filament::badge color="gray" size="xs" class="fi-meeting-attendee-badge shrink-0">
                {{ __('filament/resources/meeting.attendees.host') }}
            </x-filament::badge>
        @endif

        @if ($state['response_status'] !== null)
            @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
        @endif
    </div>
@else
    <div class="flex items-center gap-3 py-1.5">
        <x-filament::avatar
            :src="$state['avatar']"
            :alt="$state['name']"
            size="md"
            :circular="true"
            class="shrink-0"
        />

        <div class="flex min-w-0 flex-1 items-center gap-2">
            <span class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $state['name'] }}</span>

            @if ($state['is_organizer'])
                <x-filament::badge color="gray" size="sm">
                    {{ __('filament/resources/meeting.attendees.host') }}
                </x-filament::badge>
            @endif
        </div>

        @if ($state['response_status'] !== null)
            @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
        @endif
    </div>
@endif
