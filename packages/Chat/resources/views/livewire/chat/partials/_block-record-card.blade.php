{{-- Read-tool `record_card` display block. Expects the Alpine scope var
     `block`: {block, title, type, url, fields[]}.

     The heading is the record itself, as the same chip a citation renders as.
     The field rows reuse _proposal-field.blade.php verbatim, so a record card
     and a proposal card never disagree about how a field looks; that partial
     reads the scope var `field`, which the loop below binds. --}}
<div
    :data-block="block.block"
    class="rounded-2xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] p-4 shadow-sm"
>
    <template x-if="block.url">
        <a class="chat-chip" :data-record-type="block.type" :href="block.url">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="window.ChatModules.recordChipIcon(block.type)"></path>
            </svg>
            <span class="chat-chip-label" x-text="block.title"></span>
        </a>
    </template>

    <template x-if="!block.url">
        <span class="text-sm font-semibold text-gray-900 dark:text-white" x-text="block.title"></span>
    </template>

    <template x-if="Array.isArray(block.fields) && block.fields.length > 0">
        <div class="mt-3 space-y-2.5">
            <template x-for="(field, fieldIdx) in block.fields" :key="fieldIdx">
                @include('chat::livewire.chat.partials._proposal-field')
            </template>
        </div>
    </template>
</div>
