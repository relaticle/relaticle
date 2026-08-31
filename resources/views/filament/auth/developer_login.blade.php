@use(Database\Seeders\Personas\PersonaCatalog)

@env('local')
    @php($personas = PersonaCatalog::seeded())

    @if ($personas->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('Local test accounts. Password: :password', ['password' => PersonaCatalog::PASSWORD]) }}
            </p>

            <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($personas as $persona)
                    <div
                        class="flex items-center justify-between gap-3 px-3 py-2"
                        title="{{ $persona->purpose }}"
                    >
                        <x-login-link
                            :email="$persona->email"
                            :label="$persona->email"
                            :redirect-url="url()->getAppUrl()"
                            class="font-mono text-xs text-gray-600 hover:underline dark:text-gray-300"
                        />

                        <x-filament::badge :color="$persona->expect->getColor()">
                            {{ $persona->expect->getLabel() }}
                        </x-filament::badge>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
@endenv
