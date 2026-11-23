{{-- Static twin of a record list in the app panel: checkbox column, the record chip, column icons from
     CustomFieldType::icon(), a tinted header and 41px rows. The first column is the record name. --}}
@props([
    /** @var list<array{label: string, icon?: string, wide?: bool}> */
    'columns',
    /** @var list<list<string>> */
    'rows',
    'recordIcon' => 'lucide-building',
])

@php
    $head = 'h-10 whitespace-nowrap border-l border-gray-100 px-2.5 text-left text-sm font-medium text-gray-700 dark:border-white/5 dark:text-gray-200';
    $cell = 'h-[41px] whitespace-nowrap border-l border-gray-100 px-2.5 text-sm tabular-nums text-gray-950 dark:border-white/5 dark:text-white';
    $box = 'block size-4 shrink-0 rounded-md ring-1 ring-gray-300 dark:ring-white/20';
    $last = count($columns) - 1;
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
            <tr class="border-b border-gray-100 last:border-b-0 dark:border-white/5">
                @foreach($row as $index => $value)
                    @if($index === 0)
                        <th scope="row" class="{{ $cell }} max-w-0 border-l-0 pl-4 text-left font-semibold">
                            <span class="flex items-center gap-2.5">
                                <span class="{{ $box }}" aria-hidden="true"></span>
                                <span class="flex size-5 shrink-0 items-center justify-center rounded bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                                    <x-dynamic-component :component="$recordIcon" class="size-3"/>
                                </span>
                                <span class="truncate">{{ $value }}</span>
                            </span>
                        </th>
                    @else
                        <td @class([$cell, 'pr-4' => $index === $last, 'hidden sm:table-cell' => $index !== $last])>{{ $value }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
