{{-- Created/Skipped outcome for one resolved batch item, as a dot and a
     word like the row-level outcome. Expects `action` and `itemIdx` in the
     enclosing Alpine scope, with itemResult() non-null (both call sites guard
     on it); the caller sets the font size. --}}
<template x-if="itemResult(action, itemIdx).status === 'approved'">
    <span class="inline-flex items-center gap-1.5 font-medium text-gray-500 dark:text-gray-400">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-green-500" aria-hidden="true"></span>
        <span x-text="itemVerb(action)"></span>
    </span>
</template>
<template x-if="itemResult(action, itemIdx).status === 'skipped'">
    <span class="inline-flex items-center gap-1.5 font-medium text-gray-500 dark:text-gray-400">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gray-400 dark:bg-gray-500" aria-hidden="true"></span>
        <span>{{ __('Skipped') }}</span>
    </span>
</template>
