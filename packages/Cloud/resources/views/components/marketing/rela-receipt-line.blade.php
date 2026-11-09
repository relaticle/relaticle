{{-- Static twin of one receipt line in the transcript
     (packages/Chat/resources/views/livewire/chat/partials/_proposal-card-body.blade.php).
     `left_out` renders the struck, muted line a left-out write leaves. --}}
@props(['verb' => '', 'type' => 'opportunity', 'name' => '', 'meta' => null, 'outcome' => 'applied'])

@php
    $icon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
    $applied = $outcome === 'applied';
@endphp

<div {{ $attributes->class(['flex min-w-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm text-gray-700 dark:text-gray-300']) }} data-mock-receipt-line data-outcome="{{ $outcome }}">
    @if ($applied)
        <x-lucide-check class="size-4 shrink-0 text-gray-500 dark:text-gray-400" aria-hidden="true"/>
        <span class="shrink-0 whitespace-nowrap">{{ $verb }}</span>
        <span class="chat-chip min-w-0" data-record-type="{{ $type }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3 w-3" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
            </svg>
            <span class="chat-chip-label">{{ $name }}</span>
        </span>
        @if ($meta)
            <span class="min-w-0 truncate text-gray-500 dark:text-gray-400">&middot; {{ $meta }}</span>
        @endif
    @else
        <x-lucide-ban class="size-4 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true"/>
        <span class="min-w-0 truncate text-gray-500 line-through decoration-gray-300 dark:text-gray-400 dark:decoration-gray-600">{{ $name }}</span>
        <span class="shrink-0 text-gray-500 dark:text-gray-400">&middot; {{ __('left out') }}</span>
    @endif
</div>
