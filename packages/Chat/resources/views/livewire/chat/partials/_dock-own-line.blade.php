{{-- A plan step the user approves on its own (an email, an invitation): announced in the list, never counted by the plan's primary.

     Expects: $step. The line opens to the full message, read-only; the step gets its own card once nothing else is left to approve. --}}
@php
    $entityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($step['entity_type']);
    $recipient = collect($step['fields'])->firstWhere('label', 'To')['value'] ?? null;
    $lineName = $step['recordLabel'] !== '' ? $step['recordLabel'] : $step['summary'];
@endphp

<div class="group/line" wire:key="step-{{ $step['id'] }}" x-data="{ open: false }">
    <div class="flex items-start gap-2.5 px-4 py-2.5">
        <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
            @if ($entityIcon)
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-3.5 w-3.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $entityIcon }}" />
                </svg>
            @endif
        </span>

        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            class="min-w-0 flex-1 rounded-md text-start focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500"
        >
            <span class="block truncate text-sm leading-6">
                <span class="text-gray-500 dark:text-gray-400">{{ $step['followUpLabel'] }}</span>
                <span class="font-semibold text-gray-900 dark:text-white">{{ $lineName }}</span>
            </span>
            <span class="flex flex-wrap items-center gap-x-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                @if ($recipient)
                    <span class="truncate">{{ __('to :recipient', ['recipient' => $recipient]) }}</span>
                    <span aria-hidden="true">&middot;</span>
                @endif
                <x-lucide-lock class="h-3 w-3 shrink-0" aria-hidden="true" />
                <span>{{ __('you approve it on its own, next') }}</span>
            </span>
        </button>

        <x-lucide-chevron-down
            class="mt-1.5 h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform dark:text-gray-500"
            x-bind:class="open && 'rotate-180'"
            aria-hidden="true"
        />
    </div>

    <dl x-show="open" x-cloak class="mx-4 mb-3 grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-3 gap-y-1 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-xs dark:border-white/5 dark:bg-white/5">
        @foreach ($step['fields'] as $row)
            <dt class="leading-5 text-gray-500 dark:text-gray-400">{{ $row['label'] ?? '' }}</dt>
            <dd class="min-w-0 break-words leading-5">
                @include('chat::livewire.chat.partials._dock-value', ['row' => $row, 'stack' => false, 'clampable' => false])
            </dd>
        @endforeach
    </dl>
</div>
