@php
    $state = $getState();
@endphp

<div class="flex items-center gap-4">
    <div class="flex flex-col items-center justify-center rounded-lg border border-[var(--surface-block-border)] px-3 py-2 text-pico">
        <span class="font-medium uppercase">{{ $state['month'] }}</span>
        <span class="text-xl font-semibold">{{ $state['day'] }}</span>
    </div>

    <h2 class="flex-1 text-xl font-semibold">{{ $state['title'] }}</h2>

    @if ($state['response_status'] !== null && ! $state['can_respond'])
        @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
    @endif
</div>
