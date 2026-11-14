<div>
    @if ($this->showsConversations)
        <x-email-integration::reader-drawer
            :email="$this->selectedEmail"
            :pending-access-requests="$this->pendingAccessRequests"
            :thread="$this->thread"
            :bodies="$this->expandedBodies"
            :expanded="[...$expandedEmailIds, $selectedEmailId]"
            :unavailable="! $hostsReader && $this->selectedEmailUnavailable"
        />
    @else
        <x-email-integration::reader-overlay
            :email="$this->selectedEmail"
            :pending-access-requests="$this->pendingAccessRequests"
            layer="z-50"
        />
    @endif

    <x-filament-actions::modals />
</div>
