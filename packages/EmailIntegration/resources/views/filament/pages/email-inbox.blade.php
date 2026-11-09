<x-filament-panels::page class="fi-edge-to-edge-page">
    @if ($this->hasConnectedMailbox)
        {{-- ── Page tabs: the drafts, outbox and template lists, each a nested
             Livewire component also hosted by a standalone page. ────────────── --}}
        <div class="flex items-center gap-1 overflow-x-auto border-b border-gray-200/60 px-4 py-3 lg:px-6 dark:border-white/10">
            @foreach (\Relaticle\EmailIntegration\Enums\EmailPageTab::cases() as $pageTab)
                <x-email-integration::page-tab
                    :tab="$pageTab"
                    :active="$this->activeTab === $pageTab"
                    :badge="$this->tabCounts[$pageTab->value] ?? null"
                />
            @endforeach
        </div>

        @livewire($this->activeTab->livewireComponent(), [], key($this->activeTab->value.'-table'))
    @else
        <x-email-integration::not-connected
            :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
            :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            :action="$this->connectMailboxAction"
            :secondary-action="$this->connectAzureAction"
        />
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
