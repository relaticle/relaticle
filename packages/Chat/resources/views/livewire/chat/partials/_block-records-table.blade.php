{{-- Read-tool `records_table` display block. Expects the Alpine scope var
     `block`: {block, title, type, core, columns[], rows[], total}.

     Ported from the never-wired components/chat/data-table.blade.php, with two
     corrections it could not make: the record link hangs off the CORE column
     (query-aware promotion routinely puts a filtered field first), and the row
     `cells` map is read defensively because it is sparse. --}}
<div
    :data-block="block.block"
    class="overflow-hidden rounded-2xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] shadow-sm"
>
    <div class="flex items-baseline justify-between gap-2 px-3 pb-1 pt-2.5">
        <span class="text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="blockTitle(block)"></span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr>
                    <template x-for="column in (block.columns || [])" :key="column.key">
                        <th
                            scope="col"
                            class="whitespace-nowrap px-3 py-2 text-[length:var(--text-micro)] font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400"
                            x-text="blockColumnLabel(block, column)"
                        ></th>
                    </template>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                <template x-for="row in (block.rows || [])" :key="row.id">
                    <tr>
                        <template x-for="column in (block.columns || [])" :key="column.key">
                            <td class="max-w-48 px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="blockCellLinksRecord(block, row, column)">
                                    {{-- No wire:navigate: `/r/` is a server redirect, not a
                                         Livewire page, and the chip family is plain navigation. --}}
                                    <a class="chat-chip" :data-record-type="block.type" :href="row.url">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="blockChipIcon(block.type)"></path>
                                        </svg>
                                        <span class="chat-chip-label" x-text="blockCell(row, column)"></span>
                                    </a>
                                </template>
                                <template x-if="!blockCellLinksRecord(block, row, column)">
                                    <span class="block truncate" x-text="blockCell(row, column)"></span>
                                </template>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <template x-if="blockHasMore(block)">
        <div class="border-t border-[var(--surface-card-border)] px-3 py-2 text-center">
            <span class="text-[length:var(--text-micro)] text-gray-500 dark:text-gray-400" x-text="blockFooter(block)"></span>
        </div>
    </template>
</div>
