<span
    wire:key="merge-mark-{{ md5(($logo ?? '').'|'.$icon) }}"
    x-data="{ failed: false }"
    @class([
        'flex shrink-0 items-center justify-center overflow-hidden bg-gray-100 text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-gray-400 dark:ring-white/10',
        $size,
    ])
    aria-hidden="true"
>
    @if ($logo)
        <img src="{{ $logo }}" alt="" x-show="! failed" x-on:error="failed = true" class="size-full bg-white object-contain" />
    @endif

    <x-filament::icon :icon="$icon" x-show="{{ $logo ? 'failed' : 'true' }}" @class(['shrink-0', $iconSize]) />
</span>
