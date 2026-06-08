@auth
    @if(\Filament\Facades\Filament::getTenant())
        @livewire('app.chat.chat-all-chats-panel')
    @endif
@endauth
