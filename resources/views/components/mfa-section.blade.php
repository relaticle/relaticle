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

    @if ($this->pendingSecret)
        <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.mfa.scan_hint') }}</p>

            <div class="flex justify-center rounded-lg bg-white p-4">
                {!! $this->pendingQrSvg !!}
            </div>

            <div class="space-y-2">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.mfa.manual_hint') }}</p>

                <div class="flex items-center gap-2 rounded-lg bg-gray-100 p-2 dark:bg-gray-800">
                    <code class="grow px-1 font-mono text-xs break-all text-gray-900 dark:text-gray-100">{{ $this->pendingSecret }}</code>

                    <x-auth.copy-button :value="$this->pendingSecret" :label="__('profile.sections.mfa.copy_key')" />
                </div>
            </div>

            {{ ($this->confirmMfaAction)([]) }}
        </div>
    @endif

    @if ($this->enabled)
        <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
            <div class="font-medium text-gray-900 dark:text-white">{{ __('profile.sections.mfa.recovery_title') }}</div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('profile.sections.mfa.recovery_description') }}</p>

            @if ($this->revealedRecoveryCodes !== [])
                <div class="space-y-2 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                    <ul class="grid gap-2 font-mono text-sm text-gray-900 sm:grid-cols-2 dark:text-gray-100">
                        @foreach ($this->revealedRecoveryCodes as $recoveryCode)
                            <li>{{ $recoveryCode }}</li>
                        @endforeach
                    </ul>

                    <div class="flex justify-end border-t border-gray-200 pt-2 dark:border-gray-700">
                        <x-auth.copy-button
                            :value="implode(PHP_EOL, $this->revealedRecoveryCodes)"
                            :label="__('profile.sections.mfa.copy_codes')"
                        />
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                {{ ($this->showRecoveryCodesAction)([]) }}
                {{ ($this->regenerateRecoveryCodesAction)([]) }}
            </div>
        </div>
    @endif
</div>
