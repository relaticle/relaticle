<x-filament-panels::page class="fi-edge-to-edge-page">
    @unless ($this->hasConnectedMailbox)
        <x-email-integration::not-connected
            compact
            :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
            :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            :action="$this->connectMailboxAction"
            :secondary-action="$this->connectAzureAction"
        />
    @else
        <x-email-integration::mailbox-health-banner
            :mailboxes="$this->unhealthyMailboxes"
            class="border-b border-gray-200/60 dark:border-white/10"
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

    @if ($tab === null)
        <div class="divide-y divide-gray-200/60 dark:divide-white/10">
            @forelse ($this->conversations as $row)
                <x-email-integration::conversation-row :row="$row" wire:key="conversation-{{ $row->conversationKey }}" />
            @empty
                <x-filament::empty-state
                    :heading="__('filament/pages/email-inbox.views.empty.all')"
                    icon="heroicon-o-envelope"
                    icon-color="gray"
                    :contained="false"
                />
            @endforelse
        </div>
    @else
        @livewire($tab->livewireComponent(), $tab->livewireParameters(), key($tab->value.'-table'))
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
