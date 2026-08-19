<x-guest-layout>
    <div class="flex min-h-[60vh] items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            @if ($state === 'ready')
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ __('teams.accept.ready.heading', ['team' => $teamName]) }}
                </h1>

                <p class="mt-4 text-gray-600 dark:text-gray-400">
                    @if ($inviterName)
                        {{ __('teams.accept.ready.body_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
                    @else
                        {{ __('teams.accept.ready.body', ['team' => $teamName, 'role' => $roleName]) }}
                    @endif
                </p>

                <form method="POST" action="{{ $joinUrl }}" class="mt-8">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                        {{ __('teams.accept.ready.action', ['team' => $teamName]) }}
                    </button>
                </form>

                <a href="{{ url()->getAppUrl() }}" class="mt-4 inline-block text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    {{ __('teams.accept.ready.decline') }}
                </a>
            @elseif ($state === 'wrong-account')
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ __('teams.accept.wrong_account.heading') }}
                </h1>

                <p class="mt-4 text-gray-600 dark:text-gray-400">
                    {{ __('teams.accept.wrong_account.body', ['invited' => $invitedEmail, 'current' => $currentEmail]) }}
                </p>

                <form method="POST" action="{{ route('logout') }}" class="mt-8">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                        {{ __('teams.accept.wrong_account.switch') }}
                    </button>
                </form>

                <a href="{{ url()->getAppUrl() }}" class="mt-4 inline-block text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    {{ __('teams.accept.wrong_account.stay') }}
                </a>
            @else
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ __('teams.accept.expired.heading') }}
                </h1>

                <p class="mt-4 text-gray-600 dark:text-gray-400">
                    {{ __('teams.accept.expired.body') }}
                </p>

                <div class="mt-8">
                    <a href="{{ url()->getAppUrl() }}"
                       class="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                        {{ __('teams.accept.expired.action') }}
                    </a>
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
