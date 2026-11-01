<x-filament-panels::page class="fi-edge-to-edge-page">
    @unless ($this->hasConnectedMailbox)
        <x-email-integration::not-connected
            compact
            :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
            :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            :action="$this->connectMailboxAction"
            :secondary-action="$this->connectAzureAction"
        />
    @endunless

    {{-- ── Page tabs: the drafts, outbox, failed and template lists, each a nested
         Livewire component also hosted by a standalone page. ────────────── --}}
    <div data-reveal-active-tab class="flex items-center gap-1 overflow-x-auto border-b border-gray-200/60 px-4 py-3 lg:px-6 dark:border-white/10">
        @foreach (\Relaticle\EmailIntegration\Enums\EmailPageTab::cases() as $pageTab)
            <x-email-integration::page-tab
                :tab="$pageTab"
                :active="$tab === $pageTab"
                :badge="$this->tabCounts[$pageTab->value] ?? null"
            />
        @endforeach
    </div>

    @livewire($tab->livewireComponent(), $tab->livewireParameters(), key($tab->value.'-table'))

    <x-filament-actions::modals />
</x-filament-panels::page>
