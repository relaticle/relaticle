<x-layouts::filament-standalone :title="__('teams.invite_link.join.heading', ['workspace' => $team->name])">
    <div class="flex min-h-screen flex-col">
        <header class="flex justify-center px-6 pt-10">
            <a href="{{ url('/') }}">
                <x-brand.logo-lockup size="lg" class="text-black dark:text-white" />
            </a>
        </header>

        <div class="flex flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col items-center gap-4 px-6 pt-8 pb-6 text-center">
                    <img
                        src="{{ $team->getFilamentAvatarUrl() }}"
                        alt=""
                        class="size-14 rounded-xl ring-1 ring-gray-200 dark:ring-gray-700"
                    >

                    <div class="space-y-1.5">
                        <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                            {{ __('teams.invite_link.join.heading', ['workspace' => $team->name]) }}
                        </h1>

                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('teams.invite_link.join.body', ['role' => $roleName]) }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                    <div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-3 dark:border-gray-800 dark:bg-gray-800/40">
                        <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ __('teams.invite_link.join.joining_as') }}</span>
                        <span class="text-sm font-medium break-all text-gray-900 dark:text-white">{{ $user->email }}</span>
                    </div>

                    <ul class="space-y-3">
                        <li class="flex items-start gap-3">
                            <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 0 0-3-3.87M9 20H2v-1a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v1H9Zm7-13a3 3 0 1 1-1.2 5.75M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
                                </svg>
                            </span>
                            <span class="min-w-0 text-sm text-gray-600 dark:text-gray-300">
                                {{ trans_choice('teams.invite_link.join.members', $memberCount, ['count' => $memberCount]) }}
                            </span>
                        </li>

                        @if ($roleDescription)
                            <li class="flex items-start gap-3">
                                <span class="mt-px flex size-6 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5.5c0 4.2-2.9 8.1-7 9.5-4.1-1.4-7-5.3-7-9.5V6l7-3Z"/>
                                    </svg>
                                </span>
                                <span class="min-w-0 text-sm text-gray-600 dark:text-gray-300">{{ $roleDescription }}</span>
                            </li>
                        @endif
                    </ul>
                </div>

                <div class="border-t border-gray-200 px-6 py-6 dark:border-gray-800">
                    <form method="POST" action="{{ route('teams.join.confirm', ['token' => $token]) }}">
                        @csrf

                        <button type="submit" class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-primary px-4 text-sm font-medium text-white transition-colors hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            {{ __('teams.invite_link.join.action') }}
                        </button>
                    </form>

                    <a href="{{ url()->getAppUrl() }}" class="mt-3 block text-center text-sm text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        {{ __('teams.invite_link.join.decline') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-layouts::filament-standalone>
