{{-- Send-throttle countdown: the message is kept and auto-sends --}}
<template x-if="rateLimit">
    <div class="mb-2 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-900/20" role="status" aria-live="polite">
        <span class="text-xs text-amber-800 dark:text-amber-200">
            {{-- Null-safe: this effect can re-evaluate during x-if
                 teardown; throwing here aborts Alpine's flush queue
                 and silently drops queued callbacks. --}}
            You're sending fast — sending again in <span class="font-semibold tabular-nums" x-text="(rateLimit?.secondsLeft ?? 0) + 's'"></span>
            <span class="text-amber-700/70 dark:text-amber-300/70" x-text="'· ' + currentPlanLabel + ' plan'"></span>
        </span>
        <button
            type="button"
            x-on:click="clearRateLimit()"
            class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-amber-800 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900/40"
        >
            Cancel
        </button>
    </div>
</template>
