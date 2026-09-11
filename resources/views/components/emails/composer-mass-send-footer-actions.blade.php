@props([
    'showToggle' => true,
    'isMassSend' => false,
    'recipientCount' => 0,
])

@if ($showToggle)
    <div class="flex items-center gap-3">
        <x-emails.composer-mass-send-toggle />

        @if ($isMassSend && $recipientCount > 0)
            <x-emails.composer-icon-button
                icon="heroicon-o-trash"
                :label="__('filament/emails/composer.actions.discard')"
                wire:click="discard"
            />
        @endif
    </div>
@endif
