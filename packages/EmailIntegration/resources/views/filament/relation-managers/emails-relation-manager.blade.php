<div class="fi-resource-relation-manager ei-emails-relation-manager">
    {{ $this->content }}

    <x-filament-panels::unsaved-action-changes-alert />

    {{-- Same overlay as the Emails tab and the access-request bell. The stock
         Filament ViewAction modal races Alpine `isOpen` against Livewire and
         can render with `display: none`. --}}
    <x-emails.reader-overlay
        :email="$this->selectedEmail"
        :pending-access-requests="$this->pendingAccessRequests"
        layer="z-50"
    />

    <x-filament-actions::modals />
</div>
