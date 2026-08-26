<x-filament-panels::page class="[&_.fi-page-header-main-ctn]:!pb-0">
    @if ($this->showConnectPrompt)
        <div class="flex items-center justify-center overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm h-[80vh]">
            <x-emails.not-connected
                :heading="__('filament/pages/email-accounts.not_connected.inbox.heading')"
                :description="__('filament/pages/email-accounts.not_connected.inbox.description')"
            />
        </div>
    @else
    {{-- ── Page tabs: the drafts, outbox, failed and template lists, each a nested
         Livewire component also hosted by a standalone page. ────────────── --}}
    <div class="flex items-center gap-1 overflow-x-auto border-b border-gray-200 pb-2 dark:border-gray-700">
        @foreach (\Relaticle\EmailIntegration\Enums\EmailPageTab::cases() as $pageTab)
            <x-emails.page-tab
                :tab="$pageTab"
                :active="$tab === $pageTab"
                :badge="$this->tabCounts[$pageTab->value] ?? null"
            />
        @endforeach
    </div>

    {{-- No wrapper: the Filament table renders its own card. --}}
    @livewire($tab->livewireComponent(), $tab->livewireParameters(), key($tab->value.'-table'))
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
