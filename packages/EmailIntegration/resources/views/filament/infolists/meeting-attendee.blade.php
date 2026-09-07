@php
    $state = $getState();
@endphp

<div class="flex items-center gap-3">
    <img
        src="{{ $state['avatar'] }}"
        alt="{{ $state['name'] }}"
        class="size-8 shrink-0 rounded-full"
    />

    <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <span class="font-medium">{{ $state['name'] }}</span>

            @if ($state['is_organizer'])
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-pico text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    {{ __('filament/resources/meeting.attendees.host') }}
                </span>
            @endif
        </div>

        <div class="text-sm text-gray-500">{{ $state['email'] }}</div>
    </div>

    @if ($state['response_status'] !== null)
        @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
    @endif
</div>
