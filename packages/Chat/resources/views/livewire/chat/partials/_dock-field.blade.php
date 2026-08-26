{{-- One field row of a docked proposal step.

     Expects: $row (display row), $isEditable, $isEditing, $stepId, plus the
     checkbox column flags $hasCheckboxColumn / $isExcludable / $isExcluded.
     The label column is fixed so values align down the card, and a change
     renders as old → new rather than as the new value alone.

     The checkbox includes/excludes the attribute from the write (Attio): an
     unchecked field is simply not written. The required identity field shows a
     locked checked box, and rows without a code get a spacer so columns align. --}}
@php
    $hasCheckboxColumn = $hasCheckboxColumn ?? false;
    $isExcludable = $isExcludable ?? false;
    $isExcluded = $isExcluded ?? false;
@endphp
<div class="group/field flex items-start gap-3">
    @if ($hasCheckboxColumn)
        @if ($isExcludable)
            <button
                type="button"
                wire:click="toggleField(@js($row['code'] ?? ''))"
                role="checkbox"
                aria-checked="{{ $isExcluded ? 'false' : 'true' }}"
                aria-label="{{ __('Include :field', ['field' => $row['label'] ?? '']) }}"
                title="{{ $isExcluded ? __('Excluded: will not be written') : __('Included') }}"
                @class([
                    'mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500',
                    'border-primary-600 bg-primary-600 text-white' => ! $isExcluded,
                    'border-gray-300 bg-white hover:border-gray-400 dark:border-white/20 dark:bg-white/5' => $isExcluded,
                ])
            >
                @if (! $isExcluded)
                    <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
                @endif
            </button>
        @elseif (($row['code'] ?? null) !== null)
            {{-- The identity field: always written, a create without it fails. --}}
            <span
                class="mt-0.5 flex h-4 w-4 shrink-0 cursor-not-allowed items-center justify-center rounded border border-primary-600/40 bg-primary-600/40 text-white"
                title="{{ __('Required') }}"
                aria-hidden="true"
            >
                <x-heroicon-m-check class="h-3 w-3" aria-hidden="true" />
            </span>
        @else
            <span class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true"></span>
        @endif
    @endif

    <span @class([
        'w-32 shrink-0 truncate text-sm leading-5 text-gray-700 sm:w-40 dark:text-gray-300',
        'opacity-50' => $isExcluded,
    ])>{{ $row['label'] ?? '' }}</span>

    @if ($isEditing)
        <div class="w-full min-w-0">
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
            'flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm',
            'opacity-50' => $isExcluded,
        ])>
            @if (! in_array($row['value'] ?? $row['new'] ?? null, [null, ''], true) || ! empty($row['old']) || ! empty($row['values']))
                @if (! empty($row['old']))
                    <span class="text-gray-400 line-through decoration-gray-300 dark:text-gray-500 dark:decoration-gray-600">{{ $row['old'] }}</span>
                    <x-heroicon-m-arrow-right class="h-3 w-3 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                @endif

                @if (is_array($row['values'] ?? null) && $row['values'] !== [])
                    @if (($row['type'] ?? null) === 'link')
                        {{-- min-w-0 on the anchors AND their row: flex items default to
                             min-width:auto (their untruncated text width), so without it a
                             UTM-length URL bursts the card sideways, the exact measured bug
                             _proposal-field.blade.php documents. --}}
                        <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                            @foreach ($row['values'] as $url)
                                <a
                                    href="{{ (str_starts_with((string) $url, 'http') ? '' : 'https://').$url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="min-w-0 truncate text-primary-600 hover:underline dark:text-primary-400"
                                >{{ $url }}</a>
                            @endforeach
                        </span>
                    @else
                        @foreach ($row['values'] as $badge)
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[length:var(--text-micro)] font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $badge }}</span>
                        @endforeach
                    @endif
                @elseif (($row['type'] ?? null) === 'boolean')
                    <span
                        @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[length:var(--text-micro)] font-medium',
                            'bg-green-50 text-green-700 dark:bg-green-400/10 dark:text-green-400' => ($row['new'] ?? $row['value'] ?? null) === 'Yes',
                            'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' => ($row['new'] ?? $row['value'] ?? null) !== 'Yes',
                        ])
                    >{{ $row['new'] ?? $row['value'] ?? '' }}</span>
                @else
                    <span @class([
                        'font-medium text-gray-900 dark:text-white' => array_key_exists('new', $row),
                        'text-gray-700 dark:text-gray-300' => ! array_key_exists('new', $row),
                    ])>{{ $row['new'] ?? $row['value'] ?? '' }}</span>
                @endif
            @endif

            @if ($isEditable)
                <button
                    type="button"
                    wire:click="editField(@js($row['code'] ?? ''), @js($stepId))"
                    class="ms-auto inline-flex shrink-0 items-center justify-center rounded-md p-1 text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 group-hover/field:opacity-100 focus-visible:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
                    aria-label="{{ __('Edit :field', ['field' => $row['label'] ?? '']) }}"
                >
                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                </button>
            @endif
        </span>
    @endif
</div>
