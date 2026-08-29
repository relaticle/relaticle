{{-- The assistant is tenant-scoped, so it has nothing to talk to on panel pages that
     run without one (the workspace-creation wizard). Mounting it there costs a Livewire
     component and its children on first paint for no reachable UI. --}}
@auth
    @if (\Filament\Facades\Filament::getTenant())
        @livewire('app.chat.chat-side-panel', [], 'chat-side-panel')
    @endif
@endauth
