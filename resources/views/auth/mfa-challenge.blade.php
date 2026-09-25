<x-layouts::filament-standalone :title="__('auth.mfa.heading')">
    <div class="flex min-h-dvh flex-col items-center justify-center gap-8 px-4 py-8 sm:px-6">
        <header class="flex justify-center">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex w-full justify-center">
            <x-auth.mfa-challenge-form
                :action="route('two-factor.login.store')"
                :heading="__('auth.mfa.heading')"
                :account="$account"
                :expired="$expired"
                :expired-message="__('auth.mfa.expired')"
                :exit-action="route('two-factor.cancel')"
                :exit-label="$expired ? __('auth.mfa.restart') : __('auth.mfa.switch_account')"
            />
        </main>
    </div>
</x-layouts::filament-standalone>
