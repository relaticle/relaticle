<x-guest-layout title="{{ __('Join :workspace', ['workspace' => $team->name]) }}">
    <div class="flex min-h-[60vh] items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                {{ __('Join :workspace', ['workspace' => $team->name]) }}
            </h1>

            <p class="mt-4 text-gray-600 dark:text-gray-400">
                {{ __('You have been invited to join the :workspace workspace. Confirm to accept the invitation.', ['workspace' => $team->name]) }}
            </p>

            <form method="POST" action="{{ route('teams.join', ['token' => $token]) }}" class="mt-8">
                @csrf
                <button type="submit"
                        class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    {{ __('Join workspace') }}
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
