{{-- Outcome of one resolved batch item: the same check and ban icons and words
     a receipt line uses. Expects `action` and `itemIdx` in the enclosing Alpine
     scope, with itemResult() non-null (both call sites guard on it); the caller
     sets the font size. --}}
<template x-if="itemResult(action, itemIdx).status === 'approved'">
    <span class="inline-flex items-center gap-1.5 font-medium text-gray-500 dark:text-gray-400">
        <span class="inline-flex shrink-0 [&>svg]:size-3.5" aria-hidden="true" x-html="outcomeIcon('applied')"></span>
        <span x-text="itemVerb(action)"></span>
    </span>
</template>
<template x-if="itemResult(action, itemIdx).status === 'skipped'">
    <span class="inline-flex items-center gap-1.5 font-medium text-gray-500 dark:text-gray-400">
        <span class="inline-flex shrink-0 [&>svg]:size-3.5" aria-hidden="true" x-html="outcomeIcon(declinedKey(action))"></span>
        <span x-text="outcomeLabel(declinedKey(action))"></span>
    </span>
</template>
