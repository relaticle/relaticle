@props([
    'value',
    'label' => null,
    'href' => null,
    'hrefLabel' => null,
])

<div {{ $attributes->class('bg-tint border-rail flex flex-col gap-2 rounded-lg border p-1.5 sm:flex-row sm:items-center sm:gap-3 sm:pl-4') }}>
    <code class="min-w-0 flex-1 truncate px-2.5 font-mono text-sm text-gray-700 sm:px-0 dark:text-gray-200">{{ $value }}</code>

    <div class="flex shrink-0 items-center gap-1">

    <button
        type="button"
        x-data="{ copied: false }"
        x-on:click="navigator.clipboard.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
        class="inline-flex min-h-7 shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-gray-700 transition-colors hover:bg-surface hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-gray-200 dark:hover:text-white"
    >
        <x-lucide-copy x-show="! copied" class="h-3.5 w-3.5" aria-hidden="true"/>
        <x-lucide-check x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true"/>
        <span aria-live="polite" x-text="copied ? @js(__('Copied')) : @js($label ?? __('Copy'))">{{ $label ?? __('Copy') }}</span>
    </button>

    @if($href)
        <a href="{{ $href }}" class="inline-flex min-h-7 shrink-0 items-center rounded-md px-2.5 text-xs font-medium text-gray-700 underline-offset-4 transition-colors hover:text-gray-950 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-gray-200 dark:hover:text-white">
            {{ $hrefLabel ?? $href }}
        </a>
    @endif
    </div>
</div>
