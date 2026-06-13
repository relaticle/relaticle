@php
    $operation = $proposal?->operation->value;
    $createLabel = match ($operation) {
        'update' => __('Save changes'),
        'delete' => __('Delete'),
        default => __('Create'),
    };
@endphp

<div>
    @if ($proposal)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center gap-2">
                <span
                    @class([
                        'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium',
                        'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400' => $operation === 'create',
                        'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400' => $operation === 'update',
                        'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400' => $operation === 'delete',
                    ])
                >{{ $proposal->display_data['title'] ?? ucfirst((string) $operation).' '.ucfirst($proposal->entity_type) }}</span>
                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $record['summary'] ?? '' }}</span>
            </div>

            <div class="mt-2 space-y-1">
                @foreach ($recordFields as $row)
                    @php
                        $code = $row['code'] ?? null;
                        $isEditable = $code !== null && in_array($code, $editableCodes, true);
                    @endphp

                    <div class="group/field flex items-start gap-2 text-sm">
                        <span class="shrink-0 font-medium text-gray-500 dark:text-gray-400">{{ ($row['label'] ?? '').':' }}</span>

                        @if ($editingFieldCode === $code && $isEditable)
                            <div class="w-full">
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
                            <span class="flex flex-1 flex-wrap items-center gap-1">
                                @if (! in_array($row['value'] ?? $row['new'] ?? null, [null, ''], true) || ! empty($row['old']) || ! empty($row['values']))
                                    @if (! empty($row['old']))
                                        <span class="text-gray-400 line-through">{{ $row['old'] }}</span>
                                        <span class="text-gray-400" aria-hidden="true">&rarr;</span>
                                    @endif

                                    @if (is_array($row['values'] ?? null) && $row['values'] !== [])
                                        @if (($row['type'] ?? null) === 'link')
                                            @foreach ($row['values'] as $url)
                                                <a
                                                    href="{{ (str_starts_with((string) $url, 'http') ? '' : 'https://').$url }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="truncate text-primary-600 hover:underline dark:text-primary-400"
                                                >{{ $url }}</a>
                                            @endforeach
                                        @else
                                            @foreach ($row['values'] as $badge)
                                                <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $badge }}</span>
                                            @endforeach
                                        @endif
                                    @else
                                        <span class="text-gray-900 dark:text-white">{{ $row['new'] ?? $row['value'] ?? '' }}</span>
                                    @endif
                                @endif

                                @if ($isEditable)
                                    <button
                                        type="button"
                                        wire:click="editField(@js($code))"
                                        class="ml-auto inline-flex shrink-0 items-center justify-center rounded p-0.5 text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-gray-600 group-hover/field:opacity-100 focus-visible:opacity-100 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                                        aria-label="{{ __('Edit :field', ['field' => $row['label'] ?? '']) }}"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                    </button>
                                @endif
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-1">
                    @if ($recordCount > 1)
                        <button
                            type="button"
                            wire:click="stepPrev"
                            @disabled($cursor === 0)
                            class="inline-flex items-center justify-center rounded-md p-1 text-gray-500 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                            aria-label="{{ __('Previous record') }}"
                        >
                            <x-heroicon-o-chevron-left class="h-4 w-4" aria-hidden="true" />
                        </button>

                        <span class="select-none text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400">{{ $cursor + 1 }} / {{ $recordCount }}</span>

                        <button
                            type="button"
                            wire:click="stepNext"
                            @disabled($cursor === $recordCount - 1)
                            class="inline-flex items-center justify-center rounded-md p-1 text-gray-500 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-400 dark:hover:bg-gray-800"
                            aria-label="{{ __('Next record') }}"
                        >
                            <x-heroicon-o-chevron-right class="h-4 w-4" aria-hidden="true" />
                        </button>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="discardCurrent"
                        wire:loading.attr="disabled"
                        @disabled($editingFieldCode !== null)
                        @class([
                            'inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700',
                            'opacity-50' => $editingFieldCode !== null,
                        ])
                    >
                        {{ __('Discard') }}
                    </button>

                    <button
                        type="button"
                        wire:click="createCurrent"
                        wire:loading.attr="disabled"
                        @disabled($editingFieldCode !== null)
                        @class([
                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-60',
                            'bg-red-600 hover:bg-red-700' => $operation === 'delete',
                            'bg-primary-600 hover:bg-primary-700' => $operation !== 'delete',
                            'opacity-50' => $editingFieldCode !== null,
                        ])
                    >
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="createCurrent" aria-hidden="true" />
                        <x-heroicon-o-check class="h-3.5 w-3.5" wire:loading.remove wire:target="createCurrent" aria-hidden="true" />
                        <span>{{ $createLabel }}</span>
                        <kbd class="hidden rounded bg-white/20 px-1 font-sans text-[10px] sm:inline">&#8984;&#9166;</kbd>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
