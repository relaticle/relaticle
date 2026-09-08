<x-layouts::filament-standalone :title="__('auth.mfa.heading')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div
                class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-900"
                x-data="{ recovery: {{ old('recovery_code') ? 'true' : 'false' }} }"
            >
                <div class="text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
                        <x-filament::icon icon="ri-shield-keyhole-line" class="size-6 text-primary-600 dark:text-primary-400" />
                    </div>

                    <h1 class="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ __('auth.mfa.heading') }}
                    </h1>

                    <p
                        class="mt-3 text-sm text-gray-500 dark:text-gray-400"
                        x-text="recovery ? @js(__('auth.mfa.recovery_description')) : @js(__('auth.mfa.description'))"
                    >{{ old('recovery_code') ? __('auth.mfa.recovery_description') : __('auth.mfa.description') }}</p>
                </div>

                <form
                    method="POST"
                    action="{{ route('identity.confirm.mfa.store') }}"
                    class="mt-8 space-y-5"
                >
                    @csrf

                    <div x-show="! recovery">
                        <label for="code" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                            {{ __('auth.mfa.code') }}
                        </label>
                        <x-filament::input.wrapper :valid="! $errors->has('code')">
                            <x-filament::input
                                id="code"
                                name="code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                x-bind:disabled="recovery"
                                autofocus
                            />
                        </x-filament::input.wrapper>
                        <x-input-error for="code" class="mt-2" />
                    </div>

                    <div x-show="recovery" x-cloak>
                        <label for="recovery_code" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                            {{ __('auth.mfa.recovery_code') }}
                        </label>
                        <x-filament::input.wrapper :valid="! $errors->has('recovery_code')">
                            <x-filament::input
                                id="recovery_code"
                                name="recovery_code"
                                type="text"
                                autocomplete="one-time-code"
                                x-bind:disabled="! recovery"
                            />
                        </x-filament::input.wrapper>
                        <x-input-error for="recovery_code" class="mt-2" />
                    </div>

                    <x-filament::button type="submit" class="w-full justify-center">
                        {{ __('auth.mfa.continue') }}
                    </x-filament::button>

                    <button
                        type="button"
                        class="block w-full text-center text-sm font-medium text-primary-600 underline-offset-2 hover:underline dark:text-primary-400"
                        x-on:click="recovery = ! recovery"
                        x-text="recovery ? @js(__('auth.mfa.use_code')) : @js(__('auth.mfa.use_recovery_code'))"
                    ></button>
                </form>
            </div>
        </main>
    </div>
</x-layouts::filament-standalone>
