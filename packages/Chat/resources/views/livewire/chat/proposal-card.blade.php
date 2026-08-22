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
        <div class="rounded-2xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] p-4 shadow-sm">
            {{-- Header: operation icon tile + human summary + batch pager --}}
            <div class="flex items-start gap-3">
                <div
                    @class([
                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg',
                        'bg-blue-50 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400' => $operation === 'create',
                        'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400' => $operation === 'update',
                        'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400' => $operation === 'delete',
                    ])
                    aria-hidden="true"
                >
                    @if ($operation === 'update')
                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                    @elseif ($operation === 'delete')
                        <x-heroicon-o-trash class="h-3.5 w-3.5" />
                    @else
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    @endif
                </div>

                <div class="min-w-0 flex-1 pt-1">
                    <p class="text-sm font-semibold leading-5 text-gray-900 dark:text-white">{{ $record['summary'] ?? $proposal->display_data['summary'] ?? $proposal->display_data['title'] ?? '' }}</p>
                </div>

                @if ($remainingCount > 1)
                    <div class="flex shrink-0 items-center gap-0.5 pt-0.5">
                        <button
                            type="button"
                            wire:click="stepPrev"
                            @disabled($position <= 1)
                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:hover:bg-white/5 dark:hover:text-gray-300"
                            aria-label="{{ __('Previous record') }}"
                        >
                            <x-heroicon-o-chevron-left class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>

                        <span class="select-none px-0.5 text-xs font-medium tabular-nums text-gray-400 dark:text-gray-500">{{ $position }}&hairsp;/&hairsp;{{ $remainingCount }}</span>

                        <button
                            type="button"
                            wire:click="stepNext"
                            @disabled($position >= $remainingCount)
                            class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent dark:hover:bg-white/5 dark:hover:text-gray-300"
                            aria-label="{{ __('Next record') }}"
                        >
                            <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                @endif
            </div>

            @if (! empty($proposal->display_data['duplicate_warning']))
                <div class="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ $proposal->display_data['duplicate_warning'] }}</span>
                </div>
            @endif

            {{-- Field rows: fixed label column so values align, diffs render old → new --}}
            <div class="mt-3 space-y-2.5 ps-10">
                @foreach ($recordFields as $row)
                    @php
                        $code = $row['code'] ?? null;
                        $isEditable = $code !== null && in_array($code, $editableCodes, true);
                    @endphp

                    <div class="group/field flex items-start gap-3">
                        <span class="w-28 shrink-0 pt-0.5 text-xs font-medium leading-5 text-gray-500 sm:w-32 dark:text-gray-400">{{ $row['label'] ?? '' }}</span>

                        @if ($editingFieldCode === $code && $isEditable)
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
                                                 UTM-length URL bursts the card sideways — the exact measured bug
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
                                        wire:click="editField(@js($code))"
                                        class="ml-auto inline-flex shrink-0 items-center justify-center rounded-md p-1 text-gray-400 opacity-0 transition hover:bg-gray-100 hover:text-gray-600 group-hover/field:opacity-100 focus-visible:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
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

            @error('resolve')
                <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-400/10 dark:text-red-400" role="alert">
                    {{ $message }}
                </p>
            @enderror

            {{-- Footer: the decision. Separated by a hairline so it reads as one deliberate step. --}}
            <div class="mt-4 flex items-center justify-end gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
                <button
                    type="button"
                    wire:click="discardCurrent"
                    wire:loading.attr="disabled"
                    @disabled($editingFieldCode !== null)
                    @class([
                        'inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white',
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
                        'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60',
                        'bg-red-600 hover:bg-red-500' => $operation === 'delete',
                        'bg-primary-600 hover:bg-primary-500' => $operation !== 'delete',
                        'opacity-50' => $editingFieldCode !== null,
                    ])
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 animate-spin" wire:loading wire:target="createCurrent" aria-hidden="true" />
                    <x-heroicon-o-check class="h-3.5 w-3.5" wire:loading.remove wire:target="createCurrent" aria-hidden="true" />
                    <span>{{ $createLabel }}</span>
                    <kbd class="hidden rounded bg-white/20 px-1.5 py-0.5 font-sans text-[length:var(--text-pico)] font-medium sm:inline">&#8984;&#9166;</kbd>
                </button>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
