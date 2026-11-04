@php
    $name = 'billing-interval-'.\Illuminate\Support\Str::random(6);
    $segment = 'inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-full px-4 text-sm font-medium text-gray-600 transition-colors duration-200 hover:text-gray-950 has-checked:bg-white has-checked:text-gray-950 has-checked:shadow-[0_1px_2px_rgba(0,0,0,0.06)] has-checked:ring-1 has-checked:ring-gray-200/80 has-focus:outline-2 has-focus:outline-offset-2 has-focus:outline-primary dark:text-gray-400 dark:hover:text-white dark:has-checked:bg-gray-800 dark:has-checked:text-white dark:has-checked:ring-white/[0.08]';
@endphp

<div {{ $attributes->class('inline-flex flex-col items-center gap-2') }}>
    <div class="inline-flex items-center rounded-full bg-gray-100 p-1 dark:bg-white/[0.06]" role="radiogroup" aria-label="{{ __('Billing interval') }}">
        <label class="{{ $segment }}">
            <input type="radio" name="{{ $name }}" value="yearly" class="sr-only" checked x-on:change="yearly = true" x-bind:checked="yearly">
            {{ __('cloud::billing.yearly') }}
        </label>
        <label class="{{ $segment }}">
            <input type="radio" name="{{ $name }}" value="monthly" class="sr-only" x-on:change="yearly = false" x-bind:checked="! yearly">
            {{ __('cloud::billing.monthly') }}
        </label>
    </div>

    <p class="sr-only" role="status" x-text="yearly ? @js(__('Showing yearly prices')) : @js(__('Showing monthly prices'))"></p>
</div>
