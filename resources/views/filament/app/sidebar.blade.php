{{--
    The app panel's sidebar (rendered by App\Livewire\App\AppSidebar).

    This is Filament's `filament-panels::livewire.sidebar` with two changes: global
    search and the notifications trigger share a single row above the navigation,
    instead of search sitting in its own block and notifications in the footer; and
    the search field fades with the sidebar collapse rather than popping.
    Everything else is kept verbatim so it stays diffable against the vendor view.
--}}
<div>
    @php
        use Illuminate\Support\Facades\Vite;

        $navigation = filament()->getNavigation();
        $isRtl = __('filament-panels::layout.direction') === 'rtl';
        $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
        $isSidebarFullyCollapsibleOnDesktop = filament()->isSidebarFullyCollapsibleOnDesktop();
        $hasNavigation = filament()->hasNavigation();
        $hasTopbar = filament()->hasTopbar();
        $isCollapsible = $isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop;

        $cspNonce = Vite::cspNonce();
    @endphp

    {{-- format-ignore-start --}}
    <div
        x-data="filamentSidebar({
            hasTopNavigation: @js(filament()->hasTopNavigation()),
            isSidebarCollapsibleOnDesktop: @js($isSidebarCollapsibleOnDesktop),
            isSidebarFullyCollapsibleOnDesktop: @js($isSidebarFullyCollapsibleOnDesktop),
        })"
        @if ($isCollapsible)
            x-cloak
        @else
            x-cloak="-lg"
        @endif
        x-bind:class="{ 'fi-sidebar-open': $store.sidebar.isOpen }"
        x-bind:inert="isHidden"
        x-effect="focusInitialNavigation()"
        x-on:keydown.tab="handleTab($event)"
        x-on:keydown.escape.document="handleEscape($event)"
        id="fi-main-sidebar"
        class="fi-sidebar fi-main-sidebar"
    >
        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_START) }}

        <div class="fi-sidebar-header-ctn">
            <header
                class="fi-sidebar-header"
            >
                @if ((! $hasTopbar) && $isSidebarCollapsibleOnDesktop)
                    <x-filament::icon-button
                        color="gray"
                        :icon="$isRtl ? 'lucide-chevron-left' : 'lucide-chevron-right'"
                        :icon-alias="
                            $isRtl
                            ? [
                                \Filament\View\PanelsIconAlias::SIDEBAR_EXPAND_BUTTON_RTL,
                                \Filament\View\PanelsIconAlias::SIDEBAR_EXPAND_BUTTON,
                            ]
                            : \Filament\View\PanelsIconAlias::SIDEBAR_EXPAND_BUTTON
                        "
                        icon-size="lg"
                        :label="__('filament-panels::layout.actions.sidebar.expand.label')"
                        x-cloak
                        x-data="{}"
                        aria-controls="fi-main-sidebar"
                        x-bind:aria-expanded="$store.sidebar.isOpen"
                        x-on:click="$store.sidebar.open()"
                        x-show="! $store.sidebar.isOpen"
                        class="fi-sidebar-open-collapse-sidebar-btn"
                    />
                @endif

                @if ((! $hasTopbar) && $isCollapsible)
                    <x-filament::icon-button
                        color="gray"
                        :icon="$isRtl ? 'lucide-chevron-right' : 'lucide-chevron-left'"
                        :icon-alias="
                            $isRtl
                            ? [
                                \Filament\View\PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON_RTL,
                                \Filament\View\PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON,
                            ]
                            : \Filament\View\PanelsIconAlias::SIDEBAR_COLLAPSE_BUTTON
                        "
                        icon-size="lg"
                        :label="__('filament-panels::layout.actions.sidebar.collapse.label')"
                        x-cloak
                        x-data="{}"
                        aria-controls="fi-main-sidebar"
                        x-bind:aria-expanded="$store.sidebar.isOpen"
                        x-on:click="$store.sidebar.close()"
                        x-show="$store.sidebar.isOpen"
                        class="fi-sidebar-close-collapse-sidebar-btn"
                    />
                @endif

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_LOGO_BEFORE) }}

                <div
                    @if ($isCollapsible)
                        x-show="$store.sidebar.isOpen"
                    @endif
                    class="fi-sidebar-header-logo-ctn"
                >
                    @if ($homeUrl = filament()->getHomeUrl())
                        <a {{ \Filament\Support\generate_href_html($homeUrl) }}>
                            <x-filament-panels::logo />
                        </a>
                    @else
                        <x-filament-panels::logo />
                    @endif
                </div>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_LOGO_AFTER) }}

                <x-filament::icon-button
                    color="gray"
                    icon="lucide-x"
                    icon-size="lg"
                    :label="__('filament-panels::layout.actions.sidebar.collapse.label')"
                    x-data="{}"
                    aria-controls="fi-main-sidebar"
                    x-bind:aria-expanded="$store.sidebar.isOpen"
                    x-on:click="$store.sidebar.close()"
                    class="fi-sidebar-drawer-close-btn"
                />
            </header>
        </div>

        @if (filament()->hasTenancy() && filament()->hasTenantMenu())
            <x-filament-panels::tenant-menu />
        @endif

        @php
            $hasGlobalSearchInSidebar = filament()->isGlobalSearchEnabled()
                && filament()->getGlobalSearchPosition() === \Filament\Enums\GlobalSearchPosition::Sidebar;

            $hasDatabaseNotificationsInSidebar = filament()->auth()->check()
                && filament()->hasDatabaseNotifications()
                && filament()->getDatabaseNotificationsPosition() === \Filament\Enums\DatabaseNotificationsPosition::Sidebar;
        @endphp

        {{-- Search + notifications row. The search field collapses with the
             sidebar; the trigger stays, since it has no other entry point. --}}
        @if ($hasGlobalSearchInSidebar || $hasDatabaseNotificationsInSidebar)
            <div class="fi-sidebar-search-ctn">
                @if ($hasGlobalSearchInSidebar)
                    <div
                        @if ($isCollapsible)
                            x-show="$store.sidebar.isOpen"
                            x-transition:enter="fi-transition-enter"
                            x-transition:enter-start="fi-transition-enter-start"
                            x-transition:enter-end="fi-transition-enter-end"
                            x-transition:leave="fi-transition-leave"
                            x-transition:leave-start="fi-transition-leave-start"
                            x-transition:leave-end="fi-transition-leave-end"
                        @endif
                        x-on:mousedown="
                            if ($event.target.closest('.fi-input-wrp-prefix, .fi-input-wrp-suffix')) {
                                $event.preventDefault()
                                $el.querySelector('.fi-input')?.focus()
                            }
                        "
                        class="fi-sidebar-search"
                    >
                        @livewire(Filament\Livewire\GlobalSearch::class)
                    </div>
                @endif

                @if ($hasDatabaseNotificationsInSidebar)
                    @livewire(filament()->getDatabaseNotificationsLivewireComponent(), [
                        'lazy' => filament()->hasLazyLoadedDatabaseNotifications(),
                    ])
                @endif
            </div>
        @endif

        <nav
            aria-label="{{ __('filament-panels::layout.navigation.label') }}"
            class="fi-sidebar-nav"
        >
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_START) }}

            <ul class="fi-sidebar-nav-groups">
                @foreach ($navigation as $group)
                    @php
                        $isGroupActive = $group->isActive();
                        $isGroupCollapsible = $group->isCollapsible();
                        $groupIcon = $group->getIcon();
                        $groupItems = $group->getItems();
                        $groupLabel = $group->getLabel();
                        $groupExtraSidebarAttributeBag = $group->getExtraSidebarAttributeBag();
                    @endphp

                    <x-filament-panels::sidebar.group
                        :active="$isGroupActive"
                        :collapsible="$isGroupCollapsible"
                        :icon="$groupIcon"
                        :items="$groupItems"
                        :label="$groupLabel"
                        :attributes="\Filament\Support\prepare_inherited_attributes($groupExtraSidebarAttributeBag)"
                    />
                @endforeach
            </ul>

            <script @if (filled($cspNonce)) nonce="{{ $cspNonce }}" @endif>
                var collapsedGroups = JSON.parse(
                    localStorage.getItem('filament:sidebar:collapsed_groups'),
                )

                if (collapsedGroups === null || collapsedGroups === 'null') {
                    localStorage.setItem(
                        'filament:sidebar:collapsed_groups',
                        JSON.stringify(@js(
                        collect($navigation)
                            ->filter(fn (\Filament\Navigation\NavigationGroup $group): bool => $group->isCollapsed())
                            ->map(fn (\Filament\Navigation\NavigationGroup $group): ?string => ($label = $group->getLabel()) instanceof \Illuminate\Contracts\Support\Htmlable ? $label->toHtml() : $label)
                            ->values()
                            ->all()
                    )),
                    )
                }

                collapsedGroups = JSON.parse(
                    localStorage.getItem('filament:sidebar:collapsed_groups'),
                )

                document
                    .querySelectorAll('.fi-sidebar-group')
                    .forEach((group) => {
                        if (
                            !collapsedGroups.includes(group.dataset.groupLabel)
                        ) {
                            return
                        }

                        // Alpine.js loads too slow, so attempt to hide a
                        // collapsed sidebar group earlier.
                        group.querySelector(
                            '.fi-sidebar-group-items',
                        ).style.display = 'none'
                        group.classList.add('fi-collapsed')
                    })
            </script>

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_NAV_END) }}
        </nav>

        @if (filament()->auth()->check() && filament()->hasUserMenu() && filament()->getUserMenuPosition() === \Filament\Enums\UserMenuPosition::Sidebar)
            <div class="fi-sidebar-footer">
                <x-filament-panels::user-menu />
            </div>
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::SIDEBAR_FOOTER) }}
    </div>
    {{-- format-ignore-end --}}

    <x-filament-actions::modals />
</div>
