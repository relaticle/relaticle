@props([
    'recipients' => [],
    'options' => [],
])

@php
    $selectedPersonIds = collect($recipients)->pluck('personId')->sort()->values()->all();
@endphp

<aside {{ $attributes->class([
    'flex w-72 shrink-0 flex-col border-l border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
]) }}>
    <div class="border-b border-gray-200 p-3 dark:border-white/10">
        <x-emails.composer-mass-recipient-search
            :options="$options"
            :selected-person-ids="$selectedPersonIds"
        />
    </div>

    <x-emails.composer-mass-recipient-list :recipients="$recipients" />
</aside>
