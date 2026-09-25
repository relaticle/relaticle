<x-layouts::filament-standalone :title="__('mail.unsubscribe.title')">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-gray-800 dark:bg-gray-900">
                @if($subscribed)
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('mail.unsubscribe.heading') }}</h1>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('mail.unsubscribe.body', ['email' => $user->email]) }}</p>
                    <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-700">
                            {{ __('mail.unsubscribe.confirm') }}
                        </button>
                    </form>
                @else
                    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('mail.unsubscribe.done_heading') }}</h1>
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('mail.unsubscribe.done_body', ['email' => $user->email]) }}</p>
                @endif
                <a href="{{ $settingsUrl }}" class="mt-6 inline-block text-sm font-medium text-primary-700 underline dark:text-primary-300">{{ __('mail.unsubscribe.settings') }}</a>
            </div>
        </main>
    </div>
</x-layouts::filament-standalone>
