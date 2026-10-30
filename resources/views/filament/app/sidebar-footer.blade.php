@php
    /** @var \App\Models\Workspace|null $workspace */
    $workspace = \Filament\Facades\Filament::getTenant();
    $user = \Filament\Facades\Filament::auth()->user();

    // Members::canAccess() reads MembersManage (owner and admin).
    $canInviteMembers = $workspace instanceof \App\Models\Workspace
        && $user instanceof \App\Models\User
        && $user->can('manageMembers', $workspace);

    $panel = \Filament\Facades\Filament::getCurrentOrDefaultPanel();
    $isCollapsible = $panel?->isSidebarCollapsibleOnDesktop() || $panel?->isSidebarFullyCollapsibleOnDesktop();

    $extraActions = \Filament\Support\Facades\FilamentView::renderHook(\App\Providers\Filament\AppPanelProvider::SIDEBAR_FOOTER_ACTIONS);
@endphp

@if($canInviteMembers || trim($extraActions->toHtml()) !== '')
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

        {{ $extraActions }}
    </div>
@endif
