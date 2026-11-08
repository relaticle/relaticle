{{-- Static twin of the dock's primary button with its keyboard hint
     (packages/Chat/resources/views/livewire/chat/proposal-card.blade.php). --}}
@props(['label' => '', 'shortcut' => true, 'danger' => false])

<button
    type="button"
    {{ $attributes->class([
        'inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-white shadow-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        'bg-red-600 hover:bg-red-500 focus-visible:outline-red-500' => $danger,
        'bg-primary-600 hover:bg-primary-500 focus-visible:outline-primary-500' => ! $danger,
    ]) }}
>
    <span>{{ $slot->isEmpty() ? $label : $slot }}</span>
    @if ($shortcut)
        <kbd class="hidden rounded bg-white/20 px-1 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline" aria-hidden="true">&#8984;&#9166;</kbd>
    @endif
</button>
