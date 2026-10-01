@props(['card' => 'rounded-2xl border border-[var(--surface-card-border)] bg-white shadow-sm dark:bg-[var(--surface-card-bg)] dark:shadow-none'])

<div x-data="{ waited: false }" x-init="setTimeout(() => waited = true, 60000)">
    <div class="{{ $card }} flex items-center gap-3 p-5" wire:poll.3s x-show="! waited">
        <x-filament::loading-indicator class="h-5 w-5 text-primary" />
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('billing.upgrade.activating') }}</span>
    </div>

    <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-400/20 dark:bg-warning-400/[0.06]" x-show="waited" x-cloak>
        <div class="flex gap-3">
            <x-ri-time-line class="mt-0.5 h-5 w-5 shrink-0 text-warning-500 dark:text-warning-400" />
            <div>
                <h3 class="text-sm font-semibold text-warning-800 dark:text-warning-300">{{ __('billing.upgrade.activation_delayed_title') }}</h3>
                <p class="mt-0.5 text-sm text-warning-700/80 dark:text-warning-400/70">{{ __('billing.upgrade.activation_delayed_body') }}</p>
            </div>
        </div>
    </div>
</div>
