{{-- Static twin of the docked card's leave-out control
     (packages/Chat/resources/views/livewire/chat/partials/_dock-field.blade.php).
     The demo shows the proposal as drafted, so the control renders as the quiet x
     a hover reveals. `locked` is the identity field, which has no control. --}}
@props(['locked' => false, 'field' => ''])

@if ($locked)
    <span {{ $attributes->class(['inline-flex min-h-8 min-w-8 shrink-0']) }} aria-hidden="true"></span>
@else
    <span
        {{ $attributes->class(['inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 dark:text-gray-500']) }}
        aria-hidden="true"
    >
        <x-heroicon-o-x-mark class="h-3.5 w-3.5"/>
    </span>
@endif
