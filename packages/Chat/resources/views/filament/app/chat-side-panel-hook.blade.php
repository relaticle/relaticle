{{-- The assistant is tenant-scoped, so it has nothing to talk to on panel pages that
     run without one (the workspace-creation wizard). Mounting it there costs a Livewire
     component and its children on first paint for no reachable UI. --}}
@auth
    @if ($tenant = \Filament\Facades\Filament::getTenant())
        {{-- Persisted so an open panel, its transcript and a reply mid-stream survive navigation. --}}
        @persist('chat-side-panel.tenant-'.$tenant->getKey())
            @livewire('app.chat.chat-side-panel', [], 'chat-side-panel')
        @endpersist
    @endif
@endauth
