<x-layouts::filament-standalone :title="__('auth.mfa.heading')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <x-auth.mfa-challenge-form :action="route('identity.confirm.mfa.store')" />
        </main>
    </div>
</x-layouts::filament-standalone>
