@php
    /** @var \App\Models\Workspace|null $workspace */
    $workspace = \Filament\Facades\Filament::getTenant();
    $user = \Filament\Facades\Filament::auth()->user();

    // Members::canAccess() reads MembersManage (owner and admin). Billing's page is
    // open to every member, so the nudge follows its actions' BillingManage instead.
    $canInviteMembers = $workspace instanceof \App\Models\Workspace
        && $user instanceof \App\Models\User
        && $user->can('manageMembers', $workspace);

    $canManageBilling = $workspace instanceof \App\Models\Workspace
        && $user instanceof \App\Models\User
        && $user->hasWorkspaceCapability($workspace->getKey(), \App\Enums\WorkspaceCapability::BillingManage);

    $billing = $canManageBilling
        ? resolve(\App\Services\Billing\SidebarBillingState::class)->for($workspace)
        : null;

    $panel = \Filament\Facades\Filament::getCurrentOrDefaultPanel();
    $isCollapsible = $panel?->isSidebarCollapsibleOnDesktop() || $panel?->isSidebarFullyCollapsibleOnDesktop();

    // A failure gets a solid button; an offer keeps the quieter outlined pill.
    $actionClasses = $billing !== null && $billing['urgent']
        ? 'border-transparent bg-danger-600 px-2 py-1 text-white group-hover:bg-danger-500'
        : 'border-gray-200 px-1.5 py-0.5 text-gray-600 group-hover:border-gray-300 group-hover:text-gray-900 dark:border-white/10 dark:text-gray-300 dark:group-hover:text-white';
@endphp

@if($canInviteMembers || $billing !== null)
    {{-- Hidden while the sidebar is collapsed, the same way Filament gates its
         own global search. Without this the footer's intrinsic width holds the
         sidebar open and the collapse button appears to do nothing. --}}
    <div
        @if($isCollapsible)
            x-show="$store.sidebar.isOpen"
            x-cloak
        @endif
        class="fi-sidebar-footer-activation border-t border-gray-200/60 py-2 dark:border-white/10"
    >
        @livewire(\App\Livewire\App\Onboarding\ActivationChecklist::class)

        @if($canInviteMembers)
            <div class="fi-sidebar-item mx-4">
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('open-invite-workspace-members')"
                    class="fi-sidebar-item-btn w-full text-start"
                >
                    <x-heroicon-o-user-plus class="fi-icon fi-size-lg fi-sidebar-item-icon" />
                    <span class="fi-sidebar-item-label">{{ __('filament/pages/dashboard.activation.invite_members') }}</span>
                </button>
            </div>
        @endif

        @if($billing !== null)
            {{-- The whole row is the target, not just a button at its end: the
                 line states the deadline and the click acts on it, so there is
                 no dead text sitting next to a live control. --}}
            <div class="mt-2 border-t border-gray-200/60 pt-2 dark:border-white/10">
                <div class="fi-sidebar-item mx-4">
                    @if(! $billing['urgent'])
                        <button
                            type="button"
                            class="fi-sidebar-item-btn group w-full text-start"
                            x-on:click="$dispatch('open-modal', { id: @js(\App\Livewire\App\Billing\UpgradeModal::MODAL_ID) })"
                        >
                            <x-heroicon-o-arrow-up-circle class="fi-icon fi-size-lg fi-sidebar-item-icon" />

                            <span class="fi-sidebar-item-label">{{ $billing['label'] }}</span>

                            <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                                {{ $billing['action'] }}
                            </span>
                        </button>
                    @else
                        <a href="{{ resolve(\App\Contracts\Billing::class)->url($workspace) }}" class="fi-sidebar-item-btn group">
                            <x-heroicon-o-arrow-up-circle class="fi-icon fi-size-lg fi-sidebar-item-icon" />

                            <span class="fi-sidebar-item-label">{{ $billing['label'] }}</span>

                            <span class="flex-shrink-0 rounded-md border text-xs font-medium transition {{ $actionClasses }}">
                                {{ $billing['action'] }}
                            </span>
                        </a>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endif
