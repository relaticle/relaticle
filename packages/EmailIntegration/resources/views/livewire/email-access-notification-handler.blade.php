<div>
    <x-emails.reader-overlay
        :email="$this->selectedEmail"
        :pending-access-requests="$this->pendingAccessRequests"
        layer="z-50"
    />

    <x-filament-actions::modals />
</div>
