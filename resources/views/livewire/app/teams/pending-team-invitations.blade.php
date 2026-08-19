<div>
    @if ($this->hasPendingInvitations())
        {{ $this->table }}
    @endif

    <x-filament-actions::modals/>
</div>
