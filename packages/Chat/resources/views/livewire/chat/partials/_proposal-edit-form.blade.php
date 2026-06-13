{{-- Shared edit-form body for a pending create proposal (single or batch item).
     Scope var: `action`. Only rendered inside `x-if="action.edit && …"`, so every
     `action.edit.*` reference here is guarded by the caller. --}}
<template x-if="action.edit.loading">
    <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
        <span class="h-1.5 w-1.5 rounded-full bg-gray-400 motion-safe:animate-pulse" aria-hidden="true"></span>
        Loading fields…
    </div>
</template>
<template x-if="!action.edit.loading">
    <div class="space-y-3">
        <template x-for="editField in action.edit.fields" :key="editField.code">
            @include('chat::livewire.chat.partials._proposal-edit-field')
        </template>
        <template x-if="action.edit.error">
            <div class="text-xs text-red-600 dark:text-red-400" x-text="action.edit.error"></div>
        </template>
        <div class="flex items-center gap-2">
            <button type="button" x-on:click="saveProposalEdit(action)" :disabled="action.edit.saving"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-primary-300">
                <span x-text="action.edit.saving ? 'Saving…' : 'Save'"></span>
            </button>
            <button type="button" x-on:click="exitFieldEdit(action)" :disabled="action.edit.saving"
                class="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                Cancel
            </button>
        </div>
    </div>
</template>
