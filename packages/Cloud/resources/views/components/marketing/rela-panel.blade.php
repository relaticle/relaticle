{{-- Static twin of Rela's side panel (packages/Chat/resources/views/livewire/app/chat/chat-side-panel.blade.php):
     the header row, the transcript in the slot, then the `footer` slot, where the app docks a proposal or shows the message box. --}}
@props(['footer' => null])

<div {{ $attributes->class('flex min-w-0 flex-col bg-white dark:bg-gray-900') }}>
    <x-cloud::marketing.rela-panel-header/>

    <div class="flex min-h-0 flex-1 flex-col px-4 py-6">
        {{ $slot }}
    </div>

    @if ($footer)
        <div {{ $footer->attributes->class('px-4 pb-4') }}>
            {{ $footer }}
        </div>
    @endif
</div>
