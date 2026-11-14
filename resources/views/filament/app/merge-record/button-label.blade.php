{{ $label }}
<kbd @class([
    'ms-1.5 inline-flex max-sm:hidden h-5 min-w-5 items-center justify-center rounded border px-1 font-sans text-xs font-medium',
    'border-white/30 bg-white/15 text-white' => $onPrimary,
    'border-gray-950/10 bg-gray-50 text-gray-500 dark:border-white/15 dark:bg-white/5 dark:text-gray-400' => ! $onPrimary,
])>{{ $key }}</kbd>
