{{-- Read-tool `records_table` display block. Expects the Alpine scope var
     `block`: {block, title, type, core, columns[], rows[], total}.

     The record link hangs off the CORE column (query-aware promotion routinely
     puts a filtered field first), and the row `cells` map is read defensively
     because it is sparse.

     Surface: the solid data-block tier (crisp hairline card, no shadow),
     deliberately distinct from the translucent pill/chip tier, so server data
     reads as a firm object on the flat transcript. --}}
<div
    :data-block="block.block"
    class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]"
>
    {{-- Header strip: title left, truncation meta right. --}}
    <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
        <span class="flex min-w-0 items-center gap-2">
            {{-- Same glyph as the chips in the reply and the proposal cards: one
                 record type reads as one thing wherever chat draws it. --}}
            <template x-if="window.ChatModules.recordChipIcon(block.type)">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400" aria-hidden="true">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(block.type)"></path>
                    </svg>
                </span>
            </template>

            <span class="truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="blockTitle(block)"></span>
        </span>
        <template x-if="blockHasMore(block)">
            <span class="text-[length:var(--text-micro)] text-gray-400 dark:text-gray-500" x-text="blockFooter(block)"></span>
        </template>
    </div>

    {{-- tabindex + role=region: a wide table scrolls horizontally, and a
         keyboard user can only scroll a focusable region. --}}
    <div class="overflow-x-auto" tabindex="0" role="region" :aria-label="blockTitle(block)">
        <table class="w-full text-sm" :aria-label="blockTitle(block)">
            {{-- text-start on the th itself is load-bearing: the UA stylesheet
                 centers th, and a table-level text-start never beats it, which
                 left every header floating mid-column over left-aligned cells.
                 A single-column table renders no header row at all: the card
                 header already names the block, so a lone TITLE label is noise. --}}
            <thead x-show="(block.columns || []).length > 1">
                <tr>
                    <template x-for="column in (block.columns || [])" :key="column.key">
                        <th
                            scope="col"
                            class="whitespace-nowrap px-4 py-2 text-start text-[length:var(--text-micro)] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500"
                            x-text="blockColumnLabel(block, column)"
                        ></th>
                    </template>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                <template x-for="row in (block.rows || [])" :key="row.id">
                    <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                        <template x-for="column in (block.columns || [])" :key="column.key">
                            <td class="max-w-48 px-4 py-2.5 align-top text-sm text-gray-700 dark:text-gray-300">
                                <template x-if="blockCellLinksRecord(block, row, column)">
                                    {{-- No wire:navigate: `/r/` is a server redirect, not a
                                         Livewire page, and the chip family is plain navigation. --}}
                                    <a class="chat-chip" :data-record-type="blockRowType(block, row)" :href="row.url">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(blockRowType(block, row))"></path>
                                        </svg>
                                        <span class="chat-chip-label" x-text="blockCell(row, column)"></span>
                                    </a>
                                </template>
                                {{-- An `_include_<relation>` column's cell is an array of related
                                     records ({label, url, type}), not a scalar: one chip per item,
                                     matching the core column's own chip markup character for
                                     character (see chat.js's applyRecordChips comment). --}}
                                <template x-if="Array.isArray(blockCell(row, column))">
                                    <span class="flex flex-wrap gap-1">
                                        <template x-for="chip in blockCell(row, column)" :key="chip.url || chip.label">
                                            <a class="chat-chip" :data-record-type="chip.type" :href="chip.url">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(chip.type)"></path>
                                                </svg>
                                                <span class="chat-chip-label" x-text="chip.label"></span>
                                            </a>
                                        </template>
                                    </span>
                                </template>
                                <template x-if="!blockCellLinksRecord(block, row, column) && !Array.isArray(blockCell(row, column))">
                                    <span class="block truncate" :title="blockCell(row, column)" x-text="blockCell(row, column)"></span>
                                </template>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>

        <template x-if="(block.rows || []).length === 0">
            <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ __('No records to show.') }}</p>
        </template>
    </div>
</div>
