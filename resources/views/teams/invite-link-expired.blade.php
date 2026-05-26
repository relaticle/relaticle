<x-guest-layout title="{{ __('Invite Link Expired') }}">
    <div class="flex min-h-[60vh] items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('Invite Link Expired') }}
            </h1>

            <p class="mt-4 text-gray-600 dark:text-gray-400">
                {{ __('This invite link has expired. Please ask the team owner to share a new link.') }}
            </p>

            <div class="mt-8">
                <a href="{{ route('dashboard') }}"
                   class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    {{ __('Go to Dashboard') }}
                </a>
            </div>
        </div>
    </div>
</x-guest-layout>
