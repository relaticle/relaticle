@props([
    'recipients' => [],
    'options' => [],
])

@php
    $selectedPersonIds = collect($recipients)->pluck('personId')->sort()->values()->all();
@endphp

<aside {{ $attributes->class([
    'flex w-72 shrink-0 flex-col border-l border-gray-200 max-md:min-h-48 max-md:w-full max-md:flex-1 max-md:border-l-0 max-md:border-t bg-gray-50 dark:border-white/10 dark:bg-white/5',
]) }}>
    <div class="border-b border-gray-200 p-3 dark:border-white/10">
        <x-email-integration::composer-mass-recipient-search
            :options="$options"
            :selected-person-ids="$selectedPersonIds"
        />
    </div>

    <x-email-integration::composer-mass-recipient-list :recipients="$recipients" />
</aside>
