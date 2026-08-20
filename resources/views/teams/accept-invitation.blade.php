@php($pageTitle = match ($state) {
    'ready' => __('teams.accept.ready.heading', ['team' => $teamName]),
    'wrong-account' => __('teams.accept.wrong_account.heading'),
    default => __('teams.accept.expired.heading'),
})

<x-layouts::filament-standalone :title="$pageTitle">
    <div class="flex min-h-screen items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            @if ($state === 'ready')
                <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                    {{ __('teams.accept.ready.heading', ['team' => $teamName]) }}
                </h1>

                <p class="mt-4 text-gray-500 dark:text-gray-400">
                    @if ($inviterName)
                        {{ __('teams.accept.ready.body_with_inviter', ['inviter' => $inviterName, 'team' => $teamName, 'role' => $roleName]) }}
                    @else
                        {{ __('teams.accept.ready.body', ['team' => $teamName, 'role' => $roleName]) }}
                    @endif
                </p>

                <form method="POST" action="{{ $joinUrl }}" class="mt-8">
                    @csrf

                    <x-filament::button type="submit">
                        {{ __('teams.accept.ready.action', ['team' => $teamName]) }}
                    </x-filament::button>
                </form>

                <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-4 inline-block">
                    {{ __('teams.accept.ready.decline') }}
                </x-filament::link>
            @elseif ($state === 'wrong-account')
                <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                    {{ __('teams.accept.wrong_account.heading') }}
                </h1>

                <p class="mt-4 text-gray-500 dark:text-gray-400">
                    {{ __('teams.accept.wrong_account.body', ['invited' => $invitedEmail, 'current' => $currentEmail]) }}
                </p>

                <form method="POST" action="{{ route('logout') }}" class="mt-8">
                    @csrf

                    <x-filament::button type="submit">
                        {{ __('teams.accept.wrong_account.switch') }}
                    </x-filament::button>
                </form>

                <x-filament::link :href="url()->getAppUrl()" color="gray" class="mt-4 inline-block">
                    {{ __('teams.accept.wrong_account.stay') }}
                </x-filament::link>
            @else
                <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                    {{ __('teams.accept.expired.heading') }}
                </h1>

                <p class="mt-4 text-gray-500 dark:text-gray-400">
                    {{ __('teams.accept.expired.body') }}
                </p>

                <div class="mt-8">
                    <x-filament::button tag="a" :href="url()->getAppUrl()">
                        {{ __('teams.accept.expired.action') }}
                    </x-filament::button>
                </div>
            @endif
        </div>
    </div>
</x-layouts::filament-standalone>
