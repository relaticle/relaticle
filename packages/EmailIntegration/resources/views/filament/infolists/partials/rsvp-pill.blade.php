@php
    $dotColor = match ($status->getColor()) {
        'success' => 'bg-emerald-500',
        'danger' => 'bg-red-500',
        'warning' => 'bg-amber-500',
        default => 'bg-gray-400',
    };
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] px-2.5 py-1 text-xs">
    <span class="size-1.5 rounded-full {{ $dotColor }}"></span>
    {{ $status->getLabel() }}
</span>
