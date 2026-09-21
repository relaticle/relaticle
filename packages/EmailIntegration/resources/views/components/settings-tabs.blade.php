@php
    use Filament\Support\Icons\Heroicon;
    use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
    use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
    use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
    use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;

    $isPrivacyPage = $this instanceof EmailPrivacySettingsPage;
@endphp

<x-filament::tabs :label="__('filament/pages/email-privacy-settings.tabs.aria')" class="ei-tabs-segmented">
    <x-filament::tabs.item
        :active="$this instanceof EmailAccountsPage"
        tag="a"
        :href="EmailAccountsPage::getUrl()"
        :icon="Heroicon::OutlinedAtSymbol"
    >
        {{ EmailAccountsPage::getNavigationLabel() }}
    </x-filament::tabs.item>

    <x-filament::tabs.item
        :active="$this instanceof ManageEmailTemplates"
        tag="a"
        :href="EmailTemplateResource::getUrl()"
        :icon="Heroicon::OutlinedDocumentDuplicate"
    >
        {{ EmailTemplateResource::getNavigationLabel() }}
    </x-filament::tabs.item>

    @if (EmailPrivacySettingsPage::canAccess())
        @foreach (EmailPrivacySettingsPage::TABS as $tab => $icon)
            @if ($isPrivacyPage)
                <x-filament::tabs.item
                    :active="$this->tab === $tab"
                    :icon="$icon"
                    wire:click="setTab('{{ $tab }}')"
                >
                    {{ __("filament/pages/email-privacy-settings.tabs.{$tab}") }}
                </x-filament::tabs.item>
            @else
                <x-filament::tabs.item
                    tag="a"
                    :href="EmailPrivacySettingsPage::getUrl(['tab' => $tab])"
                    :icon="$icon"
                >
                    {{ __("filament/pages/email-privacy-settings.tabs.{$tab}") }}
                </x-filament::tabs.item>
            @endif
        @endforeach
    @endif
</x-filament::tabs>
