{{-- One field row of a docked proposal step.

     Expects: $row (display row), $isEditable, $isEditing, $stepId.
     The label column is fixed so values align down the card, and a change
     renders as old → new rather than as the new value alone. --}}
<div class="group/field flex items-start gap-3">
    <span class="w-24 shrink-0 text-[length:var(--text-micro)] font-medium leading-5 text-gray-400 sm:w-28 dark:text-gray-500">{{ $row['label'] ?? '' }}</span>

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
        <span class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-0.5 text-sm">
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
                    <span class="font-medium text-gray-900 dark:text-white">{{ $row['new'] ?? $row['value'] ?? '' }}</span>
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
