<x-layouts::filament-standalone :title="__('auth.confirm.heading')">
    <div class="flex min-h-dvh flex-col items-center justify-center gap-8 px-4 py-8 sm:px-6">
        <header class="flex justify-center">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex w-full justify-center">
            <x-auth.mfa-challenge-form
                :action="route('identity.confirm.mfa.store')"
                :heading="__('auth.confirm.heading')"
                :account="$account"
                :expired="$expired"
                :expired-message="__('auth.mfa.confirmation_expired')"
                :exit-action="route('identity.confirm.mfa.cancel')"
                :exit-label="__('auth.mfa.cancel')"
            />
        </main>
    </div>
</x-layouts::filament-standalone>
