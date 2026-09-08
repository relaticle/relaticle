@props(['action'])

@php($recovery = request()->has('recovery') ? request()->boolean('recovery') : $errors->has('recovery_code'))

<div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-900">
    <div class="text-center">
        <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
            <x-filament::icon icon="ri-shield-keyhole-line" class="size-6 text-primary-600 dark:text-primary-400" />
        </div>

        <h1 class="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            {{ __('auth.mfa.heading') }}
        </h1>

        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            {{ $recovery ? __('auth.mfa.recovery_description') : __('auth.mfa.description') }}
        </p>
    </div>

    <form
        method="POST"
        action="{{ $action }}"
        class="mt-8 space-y-5"
        x-data="{
            code: null,
            submitting: false,

            init() {
                this.$watch('code', (value) => {
                    if (this.submitting || (value ?? '').length !== 6) {
                        return
                    }

                    this.$nextTick(() => this.$el.requestSubmit())
                })
            },
        }"
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
                        'mx-auto',
                        '[&>input]:border-danger-600! dark:[&>input]:border-danger-500!' => $errors->has('code'),
                    ])
                >
                    <x-slot:input autofocus></x-slot:input>
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
</div>
