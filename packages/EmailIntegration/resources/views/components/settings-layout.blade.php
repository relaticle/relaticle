@php
    use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
    use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;

    $isPrivacyPage = $this instanceof EmailPrivacySettingsPage;
@endphp

@if (EmailPrivacySettingsPage::canAccess())
    <div class="flex flex-col gap-6 md:flex-row">
        @foreach ([false, true] as $isVertical)
            <div @class([
                'min-w-0 md:hidden' => ! $isVertical,
                'hidden min-w-48 shrink-0 md:block' => $isVertical,
            ])>
                <x-filament::tabs :label="__('filament/pages/email-privacy-settings.tabs.aria')" :vertical="$isVertical">
                    <x-filament::tabs.item
                        :active="$this instanceof EmailAccountsPage"
                        tag="a"
                        :href="EmailAccountsPage::getUrl()"
                        icon="lucide-at-sign"
                    >
                        {{ EmailAccountsPage::getNavigationLabel() }}
                    </x-filament::tabs.item>

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
                </x-filament::tabs>
            </div>
        @endforeach

        <div class="flex min-w-0 flex-1 flex-col gap-y-6">
            {{ $slot }}
        </div>
    </div>
@else
    <div class="flex flex-col gap-y-6">
        {{ $slot }}
    </div>
@endif
