@use(Database\Seeders\Personas\PersonaCatalog)
@use(Illuminate\Support\Facades\Route)

@if (app()->isLocal() && Route::has('loginLinkLogin'))
    @php($personas = PersonaCatalog::seeded())
    @php($members = PersonaCatalog::seededMembers())

    @if ($personas->isNotEmpty())
        <div class="mt-6">
            <p class="mb-2 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('Local test accounts. Password: :password', ['password' => PersonaCatalog::PASSWORD]) }}
            </p>

            <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-white/10 dark:border-white/10">
                @foreach ($personas as $persona)
                    @include('filament.auth.developer_login_row', [
                        'email' => $persona->email,
                        'title' => $persona->purpose,
                        'label' => $persona->expect?->getLabel() ?? __('No workspace'),
                        'color' => $persona->expect?->getColor() ?? 'gray',
                        'teammate' => false,
                    ])

                    @foreach ($members->only(array_column($persona->members, 'email')) as $email => $role)
                        @include('filament.auth.developer_login_row', [
                            'email' => $email,
                            'title' => $role->description(),
                            'label' => $role->label(),
                            'color' => 'gray',
                            'teammate' => true,
                        ])
                    @endforeach
                @endforeach
            </div>
        </div>
    @endif
@endif
