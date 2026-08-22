<x-filament-panels::page>
    @unless ($conversationId)
        {{-- Chats start on Home, so nobody should sit on a blank transcript. This
             page still answers without an id in two cases, and both must survive:
             the Home composer hands its draft over through sessionStorage before
             navigating here, and `?prompt=` deep links seed the first message. --}}
        <script>
            (() => {
                const handedOver = sessionStorage.getItem('chat:bootstrap')
                    || new URLSearchParams(window.location.search).has('prompt')

                if (!handedOver) {
                    window.location.replace(@js(\App\Filament\Pages\Dashboard::getUrl()))
                }
            })()
        </script>
    @endunless

    {{-- The shared page heading lives in the topbar. Six rem accounts for the
         topbar and page padding without leaving space for the old heading. --}}
    <div class="-mx-6 -mb-6 flex flex-1 flex-col" style="height: calc(100dvh - 6rem);">
        <livewire:chat.chat-interface
            :conversation-id="$conversationId"
            :initial-message="$initialMessage"
            :initial-model="$initialModel"
        />
    </div>
</x-filament-panels::page>
