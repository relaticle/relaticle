@props([
    'label',
    'lead',
    'tag' => 'h2',
])

<div {{ $attributes->class('border-rail border-b px-6 pb-12 pt-16 md:px-10 md:pb-14 md:pt-24') }}>
    <x-cloud::marketing.eyebrow class="mb-5">{{ $label }}</x-cloud::marketing.eyebrow>

    <{{ $tag }} class="type-title max-w-3xl">
        <span class="text-gray-950 dark:text-white">{{ $lead }}</span>
        @if($slot->isNotEmpty())
            <span class="text-gray-500">{{ $slot }}</span>
        @endif
    </{{ $tag }}>
</div>
