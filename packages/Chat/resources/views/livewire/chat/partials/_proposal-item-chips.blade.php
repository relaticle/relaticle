{{-- Approved/Skipped chip pair for one resolved batch item. Expects `action`
     and `itemIdx` in the enclosing Alpine scope, with itemResult() non-null
     (both call sites guard on it). --}}
<template x-if="itemResult(action, itemIdx).status === 'approved'">
    <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2 py-0.5 font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">
        <x-heroicon-o-check class="h-3 w-3" aria-hidden="true" /> <span x-text="itemVerb(action)"></span>
    </span>
</template>
<template x-if="itemResult(action, itemIdx).status === 'skipped'">
    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
        {{ __('Skipped') }}
    </span>
</template>
