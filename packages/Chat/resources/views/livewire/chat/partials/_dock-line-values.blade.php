{{-- The values of one plan line or batch row, as compact `label value` pairs.

     Expects: $fields (display rows), $stepId, $titleKey / $titleLabel (the identity row the line header already shows), $editableCodes,
     $index (the batch row, or null for a whole step), $skipTitle.

     Short values flow on one wrapped row. Text over 48 characters gets its own row, clamped to two lines with "more". --}}
@php
    $index ??= null;
    $skipTitle ??= true;
    $editableCodes ??= [];
    $pairs = [];
    $longRows = [];
    $editingRows = [];

    foreach ($fields as $row) {
        $code = $row['code'] ?? null;

        if ($skipTitle && ($code === null ? ($row['label'] ?? null) === $titleLabel : $code === $titleKey)) {
            continue;
        }

        $newValue = $row['new'] ?? $row['value'] ?? null;
        $oldValue = $row['old'] ?? null;
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];

        if (in_array($newValue, [null, ''], true) && in_array($oldValue, [null, ''], true) && $values === []) {
            continue;
        }

        $isEditing = $code !== null
            && $editingFieldCode === $code
            && $editingStepId === $stepId
            && ($index === null || $cursor === $index);
        $isLong = $values === []
            && ! in_array($row['type'] ?? null, ['boolean', 'link', 'badges'], true)
            && ! in_array($newValue, [null, ''], true)
            && (mb_strlen((string) $newValue) > 48 || str_contains((string) $newValue, "\n"));

        if ($isEditing) {
            $editingRows[] = $row;
        } elseif ($isLong) {
            $longRows[] = $row;
        } else {
            $pairs[] = $row;
        }
    }

    $editFor = fn (array $row): bool => ($row['code'] ?? null) !== null && in_array($row['code'], $editableCodes, true);
    $editCall = fn (array $row): string => $index === null
        ? 'editField('.\Illuminate\Support\Js::from($row['code']).', '.\Illuminate\Support\Js::from($stepId).')'
        : 'editField('.\Illuminate\Support\Js::from($row['code']).', '.\Illuminate\Support\Js::from($stepId).', '.$index.')';
@endphp

@if ($pairs !== [])
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
        @foreach ($pairs as $row)
            @if (! $loop->first)
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">&middot;</span>
            @endif

            <span class="group/pair inline-flex min-w-0 max-w-full items-baseline gap-1.5" data-proposal-field-row>
                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $row['label'] ?? '' }}</span>
                @include('chat::livewire.chat.partials._dock-value', ['row' => $row, 'stack' => false, 'clampable' => false])
                @if ($editFor($row))
                    <button
                        type="button"
                        wire:click="{{ $editCall($row) }}"
                        class="inline-flex h-5 w-5 shrink-0 items-center justify-center self-center rounded text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:opacity-0 sm:group-hover/pair:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
                        aria-label="{{ __('Edit :field', ['field' => $row['label'] ?? '']) }}"
                    >
                        <x-heroicon-o-pencil-square class="h-3 w-3" aria-hidden="true" />
                    </button>
                @endif
            </span>
        @endforeach
    </div>
@endif

@foreach ($longRows as $row)
    <div class="group/pair mt-1 flex min-w-0 items-start gap-1.5" data-proposal-field-row>
        <div class="min-w-0 flex-1">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['label'] ?? '' }}</p>
            @include('chat::livewire.chat.partials._dock-value', ['row' => $row, 'stack' => false, 'clampable' => true])
        </div>
        @if ($editFor($row))
            <button
                type="button"
                wire:click="{{ $editCall($row) }}"
                class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:opacity-0 sm:group-hover/pair:opacity-100 dark:hover:bg-white/10 dark:hover:text-gray-300"
                aria-label="{{ __('Edit :field', ['field' => $row['label'] ?? '']) }}"
            >
                <x-heroicon-o-pencil-square class="h-3 w-3" aria-hidden="true" />
            </button>
        @endif
    </div>
@endforeach

@foreach ($editingRows as $row)
    <div class="mt-1" data-proposal-field-row>
        @include('chat::livewire.chat.partials._dock-field', [
            'row' => $row,
            'stepId' => $stepId,
            'isEditable' => true,
            'isEditing' => true,
            'isExcludable' => false,
            'isExcluded' => false,
        ])
    </div>
@endforeach
