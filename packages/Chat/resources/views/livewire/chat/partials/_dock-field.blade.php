{{-- One field row of the docked single-record card.

     Expects: $row (display row), $isEditable, $isEditing, $stepId, plus the leave-out
     flags $isExcludable / $isExcluded and $clamp. The label column is fixed so values
     align down the card, and a change renders as old -> new (stacked when the pair is
     longer than 28 characters).

     The x leaves the attribute out of the write: an excluded field is simply not
     written, and the required identity field has no x. --}}
@php
    $isExcludable = $isExcludable ?? false;
    $isExcluded = $isExcluded ?? false;
    $clamp = $clamp ?? true;
    $pairLength = mb_strlen((string) ($row['old'] ?? '')) + mb_strlen((string) ($row['new'] ?? $row['value'] ?? ''));
@endphp
<div class="group/field flex items-start gap-3">
    <span @class([
        'w-24 shrink-0 truncate text-xs leading-5 text-gray-500 sm:w-28 dark:text-gray-400',
        'line-through opacity-60' => $isExcluded,
    ])>{{ $row['label'] ?? '' }}</span>

    @if ($isEditing)
        {{-- Filament loads field scripts lazily, so the editor grows after it mounts.
             Following its size keeps the editor and its Save button inside the scroller. --}}
        <div
            class="w-full min-w-0"
            x-data="{ observer: null, destroy() { this.observer?.disconnect() } }"
            x-init="observer = new ResizeObserver(() => $el.scrollIntoView({ block: 'nearest' })); observer.observe($el)"
        >
            {{ $this->form }}

            @error('field')
                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex items-center gap-2">
                <x-filament::button
                    type="button"
                    size="xs"
                    wire:click="saveField"
                    wire:loading.attr="disabled"
                    wire:target="saveField"
                >
                    {{ __('Save') }}
                </x-filament::button>

                <x-filament::button
                    type="button"
                    color="gray"
                    size="xs"
                    wire:click="cancelField"
                >
                    {{ __('Cancel') }}
                </x-filament::button>
            </div>
        </div>
    @else
        <span @class([
            'flex min-w-0 flex-1 items-start gap-1.5',
            'line-through opacity-60' => $isExcluded,
        ])>
            <span class="min-w-0 flex-1 leading-5">
                @include('chat::livewire.chat.partials._dock-value', ['row' => $row, 'stack' => $pairLength > 28, 'clampable' => $clamp])
            </span>

            @if ($isEditable)
                <button
                    type="button"
                    wire:click="editField(@js($row['code'] ?? ''), @js($stepId))"
                    class="inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:opacity-0 sm:group-hover/field:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
                    aria-label="{{ __('Edit :field', ['field' => $row['label'] ?? '']) }}"
                >
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            @endif
        </span>

        @if ($isExcludable)
            <button
                type="button"
                wire:click="toggleField(@js($row['code'] ?? ''))"
                @class([
                    'inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center rounded-md transition focus-visible:visible focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500',
                    'text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:invisible sm:group-hover/field:visible dark:hover:bg-white/10 dark:hover:text-gray-200' => ! $isExcluded,
                    'px-1.5 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400' => $isExcluded,
                ])
                aria-label="{{ $isExcluded ? __('Keep :field', ['field' => $row['label'] ?? '']) : __('Leave out :field', ['field' => $row['label'] ?? '']) }}"
            >
                @if ($isExcluded)
                    {{ __('Keep') }}
                @else
                    <x-heroicon-o-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                @endif
            </button>
        @endif
    @endif
</div>
