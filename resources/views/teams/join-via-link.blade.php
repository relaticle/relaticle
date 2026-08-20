<x-layouts::filament-standalone :title="__('teams.invite_link.join.heading', ['workspace' => $team->name])">
    <div class="flex min-h-screen items-center justify-center">
        <div class="mx-auto max-w-md px-6 py-12 text-center">
            <h1 class="text-2xl font-bold text-gray-950 dark:text-white">
                {{ __('teams.invite_link.join.heading', ['workspace' => $team->name]) }}
            </h1>

            <p class="mt-4 text-gray-500 dark:text-gray-400">
                {{ __('teams.invite_link.join.body', ['workspace' => $team->name]) }}
            </p>

            <form method="POST" action="{{ route('teams.join.confirm', ['token' => $token]) }}" class="mt-8">
                @csrf

                <x-filament::button type="submit">
                    {{ __('teams.invite_link.join.action') }}
                </x-filament::button>
            </form>
        </div>
    </div>
</x-layouts::filament-standalone>
