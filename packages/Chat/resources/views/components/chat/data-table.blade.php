@props([
    'columns' => [],
    'rows' => [],
    'totalCount' => 0,
    'entityType' => null,
])

@php
    $displayRows = array_slice($rows, 0, 10);
    $hasMore = count($rows) > 10 || $totalCount > 10;
@endphp

<div {{ $attributes->merge(['class' => 'my-2 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800']) }}>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                    @foreach($columns as $column)
                        <th class="px-3 py-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ $column['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($displayRows as $row)
                    <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        @foreach($columns as $column)
                            <td class="max-w-48 truncate px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                @if(isset($row['url']) && $loop->first)
                                    <a
                                        href="{{ $row['url'] }}"
                                        class="text-primary-600 hover:text-primary-700 dark:text-primary-400"
                                        wire:navigate
                                    >
                                        {{ $row[$column['key']] ?? '' }}
                                    </a>
                                @else
                                    {{ $row[$column['key']] ?? '' }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($hasMore)
        <div class="border-t border-gray-200 bg-gray-50 px-3 py-2 text-center dark:border-gray-700 dark:bg-gray-700/30">
            <span class="text-xs text-gray-500 dark:text-gray-400">
                Showing {{ count($displayRows) }} of {{ $totalCount }} results
            </span>
        </div>
    @endif
</div>
