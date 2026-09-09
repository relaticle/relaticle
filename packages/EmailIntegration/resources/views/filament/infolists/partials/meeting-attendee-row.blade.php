@php
    /** @var array{name: string, avatar: string, is_organizer: bool, response_status: \Relaticle\EmailIntegration\Enums\AttendeeResponseStatus|null} $state */
@endphp

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
