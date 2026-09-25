{{-- Send-throttle countdown: the message is kept and auto-sends --}}
<template x-if="rateLimit">
    <div class="mb-2 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-800 dark:bg-amber-900/20">
        {{-- The announcement is a static sr-only sentence: putting aria-live on
             the visible wrapper would re-announce the whole banner on every
             one-second tick of the countdown. --}}
        <span class="sr-only" role="status">{{ __('Sending too fast. Your message will send automatically.') }}</span>
        <span class="text-xs text-amber-800 dark:text-amber-200" aria-hidden="true">
            {{-- Null-safe: this effect can re-evaluate during x-if
                 teardown; throwing here aborts Alpine's flush queue
                 and silently drops queued callbacks. --}}
            <span x-text="@js(__('Sending too fast. Sending again in :seconds.')).replace(':seconds', (rateLimit?.secondsLeft ?? 0) + 's')"></span>
            <span class="text-amber-700/70 dark:text-amber-300/70" x-text="'· ' + @js(__(':plan plan')).replace(':plan', currentPlanLabel)"></span>
        </span>
        <button
            type="button"
            x-on:click="clearRateLimit()"
            class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500 dark:text-amber-200 dark:hover:bg-amber-900/40"
        >
            {{ __('Cancel') }}
        </button>
    </div>
</template>
