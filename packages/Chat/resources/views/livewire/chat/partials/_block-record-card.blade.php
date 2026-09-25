{{-- Read-tool `record_card` display block. Expects the Alpine scope var
     `block`: {block, title, type, url, fields[]}.

     The heading is the record itself: the same chip a citation renders when it
     links somewhere, a plain glyph and title when it does not, so a chip is
     never drawn where there is nothing to open.
     The field rows reuse _proposal-field.blade.php verbatim, so a record card
     and a proposal card never disagree about how a field looks; that partial
     reads the scope var `field`, which the loop below binds.

     Surface: the solid data-block tier (see _block-records-table). --}}
<div
    :data-block="block.block"
    class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)]"
>
    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-2.5 dark:border-white/5">
        <template x-if="!block.url && window.ChatModules.recordChipIcon(block.type)">
            <span class="flex min-w-0 items-center gap-2" data-record-title-chip :data-record-type="block.type">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(block.type)"></path>
                </svg>
                <span class="min-w-0 truncate text-sm font-semibold text-gray-900 dark:text-white" x-text="block.title"></span>
            </span>
        </template>

        <template x-if="block.url">
            <a class="chat-chip min-w-0" data-record-title-chip :data-record-type="block.type" :href="block.url">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(block.type)"></path>
                </svg>
                <span class="chat-chip-label" x-text="block.title"></span>
            </a>
        </template>

        <template x-if="!block.url && !window.ChatModules.recordChipIcon(block.type)">
            <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="block.title"></span>
        </template>
    </div>

    <template x-if="Array.isArray(block.fields) && block.fields.length > 0">
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            <template x-for="(field, fieldIdx) in block.fields" :key="fieldIdx">
                <div class="px-4 py-2" data-record-field-row>
                    @include('chat::livewire.chat.partials._proposal-field')
                </div>
            </template>
        </div>
    </template>

    <template x-if="!(Array.isArray(block.fields) && block.fields.length > 0)">
        <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ __('No fields to show.') }}</p>
    </template>
</div>
