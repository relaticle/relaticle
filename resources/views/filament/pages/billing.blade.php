<x-filament-panels::page>
    <div class="space-y-6" @if($activating) wire:poll.3s @endif>

        @if($activating)
            <x-filament::section>
                <div class="flex items-center gap-3">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    <span>{{ __('billing.upgrade.activating') }}</span>
                </div>
            </x-filament::section>
        @endif

        {{-- Current plan + usage --}}
        <x-filament::section :heading="__('billing.plan_section')">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-2xl font-bold">{{ $team->plan->label() }}</div>
                    @if($team->onGenericTrial())
                        @php($daysLeft = max(0, (int) ceil(now()->floatDiffInDays($team->trial_ends_at))))
                        <div class="text-sm text-primary-600 dark:text-primary-400">
                            {{ __('billing.trial.active_title') }} —
                            {{ trans_choice('billing.trial.days_left', $daysLeft, ['days' => $daysLeft]) }}
                        </div>
                    @endif
                </div>

                @if($balance)
                    <div class="text-left sm:text-right">
                        <div class="text-sm font-medium">{{ __('billing.usage.title') }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('billing.usage.of', ['used' => number_format($balance->credits_used), 'allowance' => number_format($team->plan->credits())]) }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('billing.usage.resets', ['date' => $balance->period_ends_at?->toFormattedDateString()]) }}</div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        @if($pastDue)
            <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger" :heading="__('billing.manage.past_due_title')">
                <p>{{ __('billing.manage.past_due_body') }}</p>
                @if($isOwner)
                    <div class="mt-3">
                        <x-filament::button wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if($onGrace)
            <x-filament::section :heading="__('billing.manage.cancel_scheduled_title')">
                <p>{{ __('billing.manage.cancel_scheduled_body', ['date' => $subscription?->ends_at?->toFormattedDateString()]) }}</p>
            </x-filament::section>
        @endif

        {{-- Actions --}}
        @if(! $isOwner)
            <x-filament::section>
                <p>{{ __('billing.member.ask_owner', ['owner' => $team->owner->name]) }}</p>
            </x-filament::section>
        @elseif($team->plan === \App\Enums\Plan::Enterprise && ! $subscription)
            <x-filament::section :heading="__('billing.enterprise.title')">
                <p>{{ __('billing.enterprise.body') }}</p>
            </x-filament::section>
        @elseif($subscription && $subscription->valid())
            <x-filament::section>
                <x-filament::button wire:click="managePortal">{{ __('billing.manage.button') }}</x-filament::button>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    @if($trialAvailable)
                        <x-filament::button wire:click="startTrial" size="lg">
                            {{ __('billing.trial.start_button') }}
                        </x-filament::button>
                    @endif

                    @if($team->onGenericTrial())
                        <x-filament::button wire:click="upgrade('monthly')" size="lg">{{ __('billing.subscribe.button') }}</x-filament::button>
                    @else
                        <x-filament::button wire:click="upgrade('monthly')" :color="$trialAvailable ? 'gray' : 'primary'">
                            {{ __('billing.upgrade.button') }} · {{ __('billing.upgrade.monthly') }}
                        </x-filament::button>
                        <x-filament::button wire:click="upgrade('yearly')" color="gray">
                            {{ __('billing.upgrade.yearly') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
