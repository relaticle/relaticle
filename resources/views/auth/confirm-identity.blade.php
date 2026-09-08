@assets
    @vite('resources/js/passkeys.js')
@endassets

<x-layouts::filament-standalone :title="__('auth.confirm.heading')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary-50 dark:bg-primary-500/10">
                        <x-filament::icon icon="ri-shield-keyhole-line" class="size-6 text-primary-600 dark:text-primary-400" />
                    </div>

                    <h1 class="mt-5 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ __('auth.confirm.heading') }}
                    </h1>

                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('auth.confirm.description') }}
                    </p>
                </div>

                @if ($hasPassword)
                    <form method="POST" action="{{ route('password.confirm.store') }}" class="mt-8 space-y-5">
                        @csrf

                        <div>
                            <label for="password" class="mb-2 block text-sm font-medium text-gray-950 dark:text-white">
                                {{ __('profile.form.password.label') }}
                            </label>
                            <x-filament::input.wrapper :valid="! $errors->has('password')">
                                <x-filament::input id="password" name="password" type="password" autocomplete="current-password" autofocus />
                            </x-filament::input.wrapper>
                            <x-input-error for="password" class="mt-2" />
                        </div>

                        <x-filament::button type="submit" class="w-full justify-center">
                            {{ __('auth.confirm.continue') }}
                        </x-filament::button>
                    </form>
                @endif

                @if ($hasPasskey)
                    <div class="mt-5" x-data="{ waiting: false }">
                        <x-filament::button
                            type="button"
                            color="gray"
                            class="w-full justify-center"
                            x-bind:disabled="waiting"
                            x-on:click="
                                waiting = true;
                                window.Passkeys.verify({ routes: { options: @js(route('passkey.confirm-options')), submit: @js(route('passkey.confirm')) } })
                                    .then((result) => { window.location.href = result.redirect; })
                                    .catch(() => { waiting = false; });
                            "
                        >
                            {{ __('auth.confirm.use_passkey') }}
                        </x-filament::button>
                    </div>
                @endif

                @if (! $hasPassword && $provider)
                    <a
                        href="{{ route('auth.socialite.confirm.redirect', ['provider' => $provider]) }}"
                        class="mt-5 block"
                    >
                        <x-filament::button color="gray" class="w-full justify-center" tag="span">
                            {{ __('auth.confirm.continue_with_provider', ['provider' => ucfirst($provider)]) }}
                        </x-filament::button>
                    </a>
                @endif
            </div>
        </main>
    </div>
</x-layouts::filament-standalone>
