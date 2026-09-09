@props(['action', 'heading', 'account', 'exitAction', 'exitLabel', 'expired' => false, 'expiredMessage'])

@php($recovery = request()->has('recovery') ? request()->boolean('recovery') : $errors->has('recovery_code'))

<div class="w-full max-w-sm rounded-2xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8 dark:border-gray-800 dark:bg-gray-900">
    <div class="text-center">
        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
            <x-filament::icon icon="ri-shield-keyhole-line" class="size-6 text-primary-600 dark:text-primary-400" />
        </div>

        <h1 class="mt-4 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">
            {{ $expired ? __('auth.mfa.expired_heading') : $heading }}
        </h1>

        @if ($account && ! $expired)
            <p class="mt-2 text-sm font-medium break-all text-gray-700 dark:text-gray-300">{{ $account }}</p>
        @endif

        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400" @if ($expired) role="alert" @endif>
            {{ $expired ? $expiredMessage : ($recovery ? __('auth.mfa.recovery_description') : __('auth.mfa.description')) }}
        </p>
    </div>

    @unless ($expired)
        <form
            method="POST"
            action="{{ $action }}"
            class="mt-6 space-y-5"
            x-data="{ code: null, submitting: false }"
            x-on:submit="if (submitting) { $event.preventDefault(); return } submitting = true"
        >
            @csrf

            @if ($recovery)
                <div>
                    <label for="recovery_code" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('auth.mfa.recovery_code') }}
                    </label>

                    <x-filament::input.wrapper :valid="! $errors->has('recovery_code')">
                        <x-filament::input
                            id="recovery_code"
                            name="recovery_code"
                            type="text"
                            class="font-mono tracking-wide"
                            :placeholder="__('auth.mfa.recovery_placeholder')"
                            autocomplete="off"
                            autocapitalize="off"
                            autocorrect="off"
                            spellcheck="false"
                            :aria-describedby="$errors->has('recovery_code') ? 'mfa-recovery-error' : null"
                            autofocus
                            required
                        />
                    </x-filament::input.wrapper>

                    <x-input-error for="recovery_code" id="mfa-recovery-error" role="alert" class="mt-2" />
                </div>
            @else
                <div>
                    <span id="mfa-code-label" class="mb-2 block text-center text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('auth.mfa.code') }}
                    </span>

                    <x-filament::input.one-time-code
                        x-model="code"
                        aria-labelledby="mfa-code-label"
                        :aria-describedby="$errors->has('code') ? 'mfa-code-error' : null"
                        @class([
                            'w-full! [&>input]:min-w-0 [&>input]:flex-1',
                            '[&>input]:border-danger-600! dark:[&>input]:border-danger-500!' => $errors->has('code'),
                        ])
                    >
                        <x-slot:input autofocus required aria-labelledby="mfa-code-label"></x-slot:input>
                    </x-filament::input.one-time-code>

                    <input type="hidden" name="code" x-bind:value="code ?? ''">

                    <x-input-error for="code" id="mfa-code-error" role="alert" class="mt-2 text-center" />
                </div>
            @endif

            <x-filament::button type="submit" class="w-full justify-center" x-bind:disabled="submitting">
                <span x-text="submitting ? @js(__('auth.mfa.verifying')) : @js(__('auth.mfa.continue'))">{{ __('auth.mfa.continue') }}</span>
            </x-filament::button>

            <a
                href="{{ request()->fullUrlWithQuery(['recovery' => $recovery ? 0 : 1]) }}"
                class="block w-full text-center text-sm font-medium text-primary-600 underline-offset-2 hover:underline dark:text-primary-400"
            >{{ $recovery ? __('auth.mfa.use_code') : __('auth.mfa.use_recovery_code') }}</a>
        </form>
    @endunless

    <form method="POST" action="{{ $exitAction }}" class="mt-6 border-t border-gray-200 pt-5 text-center dark:border-gray-800">
        @csrf

        @if ($expired)
            <x-filament::button type="submit" class="w-full justify-center">
                {{ $exitLabel }}
            </x-filament::button>
        @else
            <button type="submit" class="text-sm font-medium text-gray-500 underline-offset-2 hover:text-gray-950 hover:underline dark:text-gray-400 dark:hover:text-white">
                {{ $exitLabel }}
            </button>
        @endif
    </form>
</div>
