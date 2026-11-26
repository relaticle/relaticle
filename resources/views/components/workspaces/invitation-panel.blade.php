@props([
    'headingTag' => 'h1',
    'headingId' => null,
])

<div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
    {{ $icon }}

    <div class="space-y-1.5">
        <{{ $headingTag }} @if ($headingId) id="{{ $headingId }}" @endif class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
            {{ $heading }}
        </{{ $headingTag }}>

        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $description }}
        </p>
    </div>
</div>

@isset($details)
    <div class="space-y-5 border-t border-gray-200 px-6 py-6 dark:border-gray-800">
        {{ $details }}
    </div>
@endisset

<div class="fi-invitation-actions border-t border-gray-200 px-6 py-6 dark:border-gray-800">
    {{ $actions }}
</div>
