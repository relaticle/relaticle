<x-filament-panels::page>
    <div class="-mx-6 -mb-6 flex flex-1 flex-col" style="height: calc(100vh - 10rem);">
        <livewire:chat.chat-interface
            :conversation-id="$conversationId"
            :initial-message="$initialMessage"
            :initial-model="$initialModel"
        />
    </div>
</x-filament-panels::page>
