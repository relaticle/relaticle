@props([
    'box' => 'size-5',
    'glyph' => 'size-3',
    'initialsSize' => 'text-[9px]',
])

<img
    x-show="chip.avatarUrl"
    x-bind:src="chip.avatarUrl"
    alt=""
    @class(['shrink-0 object-cover ring-1 ring-gray-950/5 dark:ring-white/10', $box])
    x-bind:class="chip.circular ? 'rounded-full' : 'rounded'"
/>
<span
    x-show="! chip.avatarUrl && chip.iconPath"
    @class([
        'flex shrink-0 items-center justify-center bg-gray-100 text-gray-600 ring-1 ring-gray-950/5',
        'dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
        $box,
    ])
    x-bind:class="chip.circular ? 'rounded-full' : 'rounded'"
>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" @class([$glyph]) aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" x-bind:d="chip.iconPath" />
    </svg>
</span>
<span
    x-show="! chip.avatarUrl && ! chip.iconPath"
    @class([
        'flex shrink-0 select-none items-center justify-center font-semibold ring-1 ring-inset ring-white/60',
        '[background-color:color-mix(in_oklab,var(--color-500)_14%,transparent)] [color:var(--color-600)]',
        'dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10',
        $box,
        $initialsSize,
    ])
    x-bind:class="(chip.circular ? 'rounded-full' : 'rounded') + ' fi-color-' + (chip.avatarColor || 'primary')"
    x-text="initials(chip.label)"
></span>
