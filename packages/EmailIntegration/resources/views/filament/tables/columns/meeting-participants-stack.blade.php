@php
    /** @var \Relaticle\EmailIntegration\Models\Meeting $record */
    $record = $getRecord();
    $stack = resolve(\Relaticle\EmailIntegration\Services\MeetingParticipantStackPresenter::class)->forMeeting($record);
@endphp

<div class="px-3">
@if ($stack['avatars'] === [])
    <span class="text-sm text-gray-400 dark:text-gray-500">&mdash;</span>
@else
    <div class="flex items-center gap-1.5">
        <span class="flex -space-x-1.5" aria-hidden="true">
            @foreach ($stack['avatars'] as $avatar)
                <span
                    x-tooltip="{
                        content: @js($avatar['tooltip']),
                        theme: $store.theme,
                    }"
                    class="inline-flex"
                >
                    @include('email-integration::filament.infolists.partials.meeting-attendee-avatar', [
                        'src' => $avatar['src'],
                        'alt' => $avatar['alt'],
                        'hasName' => $avatar['has_name'],
                        'size' => 'sm',
                        'class' => 'ring-2 ring-white dark:ring-gray-900',
                    ])
                </span>
            @endforeach
        </span>

        @if ($stack['overflow'] > 0)
            <span
                class="text-xs text-gray-500 dark:text-gray-400"
                x-tooltip="{
                    content: @js($stack['overflow_tooltip']),
                    theme: $store.theme,
                }"
            >
                {{ __('filament/pages/dashboard.meetings.more_participants', ['count' => $stack['overflow']]) }}
            </span>
        @endif
    </div>
@endif
</div>
