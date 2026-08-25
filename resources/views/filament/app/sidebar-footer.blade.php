@php
    /** @var \App\Models\Team|null $team */
    $team = \Filament\Facades\Filament::getTenant();
    $user = \Filament\Facades\Filament::auth()->user();

    // Every row below links somewhere only a workspace admin can act on:
    // Members::canAccess() is can('update', $tenant), and the billing page is
    // the same. Showing them to an editor would be a footer of 403s.
    $canManage = $team instanceof \App\Models\Team
        && $user instanceof \App\Models\User
        && $user->can('update', $team);

    $billing = $canManage
        ? resolve(\App\Services\Billing\SidebarBillingState::class)->for($team)
        : null;

    $panel = \Filament\Facades\Filament::getCurrentOrDefaultPanel();
    $isCollapsible = $panel?->isSidebarCollapsibleOnDesktop() || $panel?->isSidebarFullyCollapsibleOnDesktop();

    // Mirrors a nav item's geometry so the footer reads as part of the same
    // list rather than a stack bolted underneath it.
    $rowClasses = 'mx-4 flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5';
@endphp

@if($canManage)
    {{-- Hidden while the sidebar is collapsed, the same way Filament gates its
         own global search. Without this the footer's intrinsic width holds the
         sidebar open and the collapse button appears to do nothing. --}}
    <div
        @if($isCollapsible)
            x-show="$store.sidebar.isOpen"
            x-cloak
        @endif
        class="fi-sidebar-footer-activation border-t border-gray-200 py-2 dark:border-white/10"
    >
        @livewire(\App\Livewire\App\Onboarding\ActivationChecklist::class)

        <div class="border-t border-gray-200 pt-2 dark:border-white/10">
            <a href="{{ \App\Filament\Pages\Team\Members::getUrl() }}" class="{{ $rowClasses }}">
                <x-heroicon-o-user-plus class="h-5 w-5 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                <span class="truncate">{{ __('filament/pages/dashboard.activation.invite_members') }}</span>
            </a>
        </div>

        @if($billing !== null)
            {{-- The whole row is the target, not just a button at its end: the
                 line states the deadline and the click acts on it, so there is
                 no dead text sitting next to a live control. --}}
            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-white/10">
                <a href="{{ \App\Filament\Pages\Billing::getUrl() }}" class="{{ $rowClasses }} group">
                    <x-heroicon-o-arrow-up-circle class="h-5 w-5 flex-shrink-0 text-gray-400 dark:text-gray-500" />

                    <span class="flex-1 truncate">{{ $billing['label'] }}</span>

                    <span class="flex-shrink-0 rounded-md border border-gray-200 px-1.5 py-0.5 text-xs font-medium text-gray-600 transition group-hover:border-gray-300 group-hover:text-gray-900 dark:border-white/10 dark:text-gray-300 dark:group-hover:text-white">
                        {{ $billing['action'] }}
                    </span>
                </a>
            </div>
        @endif
    </div>
@endif
