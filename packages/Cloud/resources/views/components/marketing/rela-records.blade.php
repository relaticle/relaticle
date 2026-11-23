{{-- Static twin of the records table Rela draws for a read
     (packages/Chat/resources/views/livewire/chat/partials/_block-records-table.blade.php). A cell given as `['chip' => ...]` is the record's chip. --}}
@props([
    'title',
    'type',
    'sort' => null,
    /** @var list<string> */
    'columns',
    /** @var list<list<string|array{chip: string}>> */
    'rows',
])

@php
    $icon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath($type);
@endphp

<div {{ $attributes->class('overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]') }}>
    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
        </span>
        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</span>
        @if ($sort)
            <span class="truncate text-sm text-gray-500 dark:text-gray-400">&middot; {{ __('By :column, ascending', ['column' => $sort]) }}</span>
        @endif
    </div>

    <div class="overflow-x-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th scope="col" class="whitespace-nowrap px-4 py-2 text-start text-micro font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $value)
                            <td class="max-w-48 px-4 py-2.5 align-top text-sm text-gray-700 dark:text-gray-300">
                                @if (is_array($value))
                                    <span class="chat-chip" data-record-type="{{ $type }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                                        <span class="chat-chip-label">{{ $value['chip'] }}</span>
                                    </span>
                                @else
                                    <span class="block truncate">{{ $value }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
