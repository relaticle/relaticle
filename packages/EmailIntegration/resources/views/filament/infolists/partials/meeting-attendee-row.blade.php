@php
    /** @var array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: \Relaticle\EmailIntegration\Enums\AttendeeResponseStatus|null} $state */
    $showEmail ??= $state['email'] !== '' && mb_strtolower($state['name']) !== $state['email'];
@endphp

<div class="flex items-center gap-3 py-1.5">
    @include('email-integration::filament.infolists.partials.meeting-attendee-avatar', [
        'src' => $state['avatar'],
        'alt' => $state['name'],
        'hasName' => $state['has_name'],
        'size' => 'md',
    ])

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 items-center gap-2">
            <div class="truncate text-sm font-medium leading-5 text-gray-950 dark:text-white">{{ $state['name'] }}</div>

            @if ($state['is_organizer'])
                <x-filament::badge color="gray" size="sm" class="shrink-0">
                    {{ __('filament/resources/meeting.attendees.host') }}
                </x-filament::badge>
            @endif
        </div>

        @if ($showEmail)
            <div class="truncate text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $state['email'] }}</div>
        @endif
    </div>

    @if ($state['response_status'] !== null)
        <div class="shrink-0">
            @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
        </div>
    @endif
</div>
