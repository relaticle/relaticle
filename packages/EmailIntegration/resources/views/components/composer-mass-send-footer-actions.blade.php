@props([
    'showToggle' => true,
    'isMassSend' => false,
    'recipientCount' => 0,
])

@if ($showToggle)
    <div class="flex items-center gap-3 max-sm:ms-auto">
        <x-email-integration::composer-mass-send-toggle />

        @if ($isMassSend && $recipientCount > 0)
            <x-email-integration::composer-icon-button
                icon="lucide-trash"
                :label="__('filament/emails/composer.actions.discard')"
                wire:click="discard"
            />
        @endif
    </div>
@endif
