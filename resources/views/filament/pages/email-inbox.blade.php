<x-filament-panels::page class="[&_.fi-page-header-main-ctn]:!pb-0">
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

    <x-filament-actions::modals />
</x-filament-panels::page>
