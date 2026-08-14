{{-- One attachment, as a card: a file-type badge, the name, and its size underneath.
     The trailing slot is the control — a download link in the reader, a remove button
     in the composer — so both surfaces read as the same object.

     `size` is bytes; pass null while a file is still being processed and the line
     falls back to `placeholder`. --}}
@props([
    'filename',
    'size' => null,
    'placeholder' => null,
])

@php
    $extension = mb_strtoupper(pathinfo((string) $filename, PATHINFO_EXTENSION));

    // Tint by family, not by extension: a dozen image types should not be a dozen colours.
    $tint = match (mb_strtolower($extension)) {
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'svg' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-400',
        'pdf' => 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400',
        'doc', 'docx', 'rtf', 'txt', 'md' => 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400',
        'xls', 'xlsx', 'csv', 'numbers' => 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400',
        default => 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400',
    };
@endphp

<div {{ $attributes->class(['flex max-w-64 items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-2.5 py-1.5 dark:border-gray-700 dark:bg-gray-900']) }}>
    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[9px] font-bold leading-none {{ $tint }}">
        @if ($extension === '')
            <x-heroicon-m-paper-clip class="h-4 w-4" />
        @else
            {{ mb_substr($extension, 0, 4) }}
        @endif
    </span>

    <span class="flex min-w-0 flex-1 flex-col">
        <span class="truncate text-xs font-medium text-gray-900 dark:text-gray-100" title="{{ $filename }}">
            {{ $filename }}
        </span>
        <span class="truncate text-[11px] tabular-nums text-gray-500 dark:text-gray-400">
            {{ $size === null ? $placeholder : \Illuminate\Support\Number::fileSize($size, precision: 0) }}
        </span>
    </span>

    {{ $slot }}
</div>
