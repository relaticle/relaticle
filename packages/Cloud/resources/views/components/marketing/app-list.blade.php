{{-- Static twin of a record list in the app panel: checkbox column, the record chip, column icons from
     CustomFieldType::icon(), a tinted header and 41px rows. The first column is the record name.
     A cell is a string, a select badge (`badge`, `color`), a linked record (`record`, `icon`, `shape`, `tone`),
     or a list of those, shown one at a time through their `attributes`. A row is a list of cells, or
     `cells` with the row's own `attributes`. --}}
@props([
    /** @var list<array{label: string, icon?: string}> */
    'columns',
    /** @var list<list<mixed>|array{cells: list<mixed>, attributes?: array<string, mixed>}> */
    'rows',
    'recordIcon' => 'lucide-building',
    'recordShape' => 'square',
])

@php
    $head = 'h-10 whitespace-nowrap border-l border-gray-100 px-2.5 text-left text-sm font-medium text-gray-700 dark:border-white/5 dark:text-gray-200';
    $cell = 'h-[41px] whitespace-nowrap border-l border-gray-100 px-2.5 text-sm tabular-nums text-gray-950 dark:border-white/5 dark:text-white';
    $box = 'block size-4 shrink-0 rounded-md ring-1 ring-gray-300 dark:ring-white/20';
    $tile = 'flex size-5 shrink-0 items-center justify-center bg-gray-100 text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10';
    $last = count($columns) - 1;
    $palette = fn (string $hex): string => collect(\Filament\Support\Colors\Color::hex($hex))
        ->only([50, 300, 400, 600, 700])
        ->map(fn (string $shade, int $step): string => "--badge-{$step}: {$shade}")
        ->join('; ');
    $parts = fn (mixed $value): array => is_array($value) && array_is_list($value) ? $value : [$value];
@endphp

<table {{ $attributes->class('w-full border-t border-gray-100 dark:border-white/[0.06]') }}>
    <thead class="bg-gray-50 dark:bg-white/[0.03]">
        <tr class="border-b border-gray-100 dark:border-white/5">
            @foreach($columns as $index => $column)
                <th scope="col" @class([$head, 'w-full border-l-0 pl-4' => $index === 0, 'pr-4' => $index === $last, 'hidden sm:table-cell' => $index !== 0 && $index !== $last])>
                    <span class="flex items-center gap-2.5">
                        @if($index === 0)
                            <span class="{{ $box }}" aria-hidden="true"></span>
                        @else
                            <x-dynamic-component :component="$column['icon']" class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true"/>
                        @endif
                        {{ $column['label'] }}
                    </span>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            <tr {{ (new \Illuminate\View\ComponentAttributeBag($row['attributes'] ?? []))->class('border-b border-gray-100 last:border-b-0 dark:border-white/5') }}>
                @foreach($row['cells'] ?? $row as $index => $value)
                    @if($index === 0)
                        <th scope="row" class="{{ $cell }} max-w-0 border-l-0 pl-4 text-left font-normal">
                            <span class="flex items-center gap-2.5">
                                <span class="{{ $box }}" aria-hidden="true"></span>
                                @if($recordIcon)
                                    <span @class([$tile, $recordShape === 'round' ? 'rounded-full' : 'rounded']) aria-hidden="true">
                                        <x-dynamic-component :component="$recordIcon" class="size-3"/>
                                    </span>
                                @endif
                                <span @class(['truncate', 'font-medium' => ! $recordIcon])>{{ $value }}</span>
                            </span>
                        </th>
                    @else
                        <td @class([$cell, 'pr-4' => $index === $last, 'hidden sm:table-cell' => $index !== $last])>
                            @foreach($parts($value) as $part)
                                @if(is_array($part) && isset($part['badge']))
                                    <span {{ (new \Illuminate\View\ComponentAttributeBag($part['attributes'] ?? []))->merge(['style' => $palette($part['color'])])->class('inline-flex items-center rounded-lg bg-(--badge-50) px-1.5 py-0.5 text-xs font-medium text-(--badge-700) inset-ring inset-ring-(--badge-600)/10 dark:bg-(--badge-400)/10 dark:text-(--badge-300) dark:inset-ring-(--badge-400)/30') }}>{{ $part['badge'] }}</span>
                                @elseif(is_array($part) && isset($part['record']))
                                    <span {{ (new \Illuminate\View\ComponentAttributeBag($part['attributes'] ?? []))->class(['inline-flex max-w-full items-center gap-2', 'text-primary-600 dark:text-primary-400' => ($part['tone'] ?? 'link') === 'link']) }}>
                                        <span @class([$tile, ($part['shape'] ?? 'square') === 'round' ? 'rounded-full' : 'rounded']) aria-hidden="true">
                                            <x-dynamic-component :component="$part['icon'] ?? 'lucide-building'" class="size-3"/>
                                        </span>
                                        <span @class(['truncate', 'underline decoration-gray-300 underline-offset-4 dark:decoration-gray-600' => ($part['tone'] ?? 'link') === 'underline'])>{{ $part['record'] }}</span>
                                    </span>
                                @elseif(is_array($part))
                                    <span {{ new \Illuminate\View\ComponentAttributeBag($part['attributes'] ?? []) }}>{{ $part['text'] ?? '' }}</span>
                                @else
                                    {{ $part }}
                                @endif
                            @endforeach
                        </td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
