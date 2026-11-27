@auth
    {{-- Livewire stubs a child whose key it has seen, so a fixed key would keep refresh-sidebar from repainting it. --}}
    @livewire('app.favorites-sidebar-nav', [], key('favorites-sidebar-nav-'.\Illuminate\Support\Str::random(8)))
@endauth
