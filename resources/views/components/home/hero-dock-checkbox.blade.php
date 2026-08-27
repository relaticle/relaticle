{{-- Static twin of the docked proposal's attribute checkbox
     (packages/Chat/resources/views/livewire/chat/partials/_dock-field.blade.php).
     Always rendered checked: the hero shows a proposal exactly as the agent
     drafted it, before anyone has unchecked anything.

     `locked` is the identity field's variant — always written, so the real card
     renders it at 40% tint with a not-allowed cursor instead of a live control. --}}
@props(['locked' => false])

<span
    {{ $attributes->class([
        'flex h-4 w-4 shrink-0 items-center justify-center rounded border text-white',
        'border-primary-600 bg-primary-600' => ! $locked,
        'border-primary-600/40 bg-primary-600/40' => $locked,
    ]) }}
    aria-hidden="true"
>
    <x-heroicon-m-check class="h-3 w-3"/>
</span>
