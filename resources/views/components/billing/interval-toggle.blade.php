@php
    $segment = 'inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-full px-4 text-sm font-medium transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $active = 'bg-white text-gray-950 shadow-[0_1px_2px_rgba(0,0,0,0.06)] ring-1 ring-gray-200/80 dark:bg-gray-800 dark:text-white dark:ring-white/[0.08]';
    $inactive = 'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white';
@endphp

<div {{ $attributes->class('inline-flex items-center rounded-full bg-gray-100 p-1 dark:bg-white/[0.06]') }} role="group" aria-label="{{ __('Billing interval') }}">
    <button type="button" @click="yearly = true" :aria-pressed="yearly" :class="yearly ? @js($active) : @js($inactive)" class="{{ $segment }}">
        {{ __('billing.pro_plan.yearly') }}
        <span :class="yearly ? 'bg-primary/[0.1] text-primary-700 dark:bg-primary/[0.25] dark:text-primary-300' : 'bg-gray-200/80 text-gray-600 dark:bg-white/[0.08] dark:text-gray-400'" class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold leading-none transition-colors duration-200">{{ __('billing.pro_plan.yearly_save') }}</span>
    </button>
    <button type="button" @click="yearly = false" :aria-pressed="!yearly" :class="yearly ? @js($inactive) : @js($active)" class="{{ $segment }}">
        {{ __('billing.pro_plan.monthly') }}
    </button>
</div>
