<div {{ $attributes->class('inline-flex items-center rounded-lg bg-[var(--surface-input-bg)] p-1 ring-1 ring-inset ring-[var(--surface-input-border)]') }} role="group" aria-label="{{ __('Billing interval') }}">
    <button type="button" @click="yearly = true" :aria-pressed="yearly" :class="yearly ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 dark:text-gray-400'" class="min-h-9 rounded-md px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        {{ __('billing.pro_plan.yearly') }}
    </button>
    <button type="button" @click="yearly = false" :aria-pressed="!yearly" :class="!yearly ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 dark:text-gray-400'" class="min-h-9 rounded-md px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
        {{ __('billing.pro_plan.monthly') }}
    </button>
</div>
