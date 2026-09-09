<div class="space-y-4">
    <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <div class="space-y-1">
            <div class="flex items-center gap-2 font-medium text-gray-900 dark:text-white">
                <x-filament::icon
                    :icon="$this->enabled ? 'ri-shield-check-fill' : 'ri-shield-line'"
                    @class([
                        'size-5',
                        'text-success-600 dark:text-success-400' => $this->enabled,
                        'text-gray-400 dark:text-gray-500' => ! $this->enabled,
                    ])
                />
                <span>{{ $this->enabled ? __('profile.sections.mfa.status_enabled') : __('profile.sections.mfa.title') }}</span>
            </div>

            @unless ($this->enabled)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('profile.sections.mfa.status_disabled') }}
                </p>
            @endunless
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @if ($this->enabled)
                {{ ($this->disableMfaAction)([]) }}
            @else
                {{ ($this->enableMfaAction)([]) }}
            @endif
        </div>
    </div>

    @if ($this->enabled)
        <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="font-medium text-gray-900 dark:text-white">{{ __('profile.sections.mfa.recovery_title') }}</div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.mfa.recovery_description') }}</p>

            @if ($this->revealedRecoveryCodes !== [] && $this->mountedActions === [])
                @include('components.mfa-recovery-codes')
            @endif

            <div class="flex flex-wrap items-center gap-3">
                {{ ($this->showRecoveryCodesAction)([]) }}
                {{ ($this->regenerateRecoveryCodesAction)([]) }}
            </div>
        </div>
    @endif
</div>
