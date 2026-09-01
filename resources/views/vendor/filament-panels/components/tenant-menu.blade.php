{{--
    Filament's `filament::components.tenant-menu`, with enter and leave
    transitions on the workspace name. The vendor view toggles it with a bare
    `x-show`, so it popped in and out of the sidebar collapse animation.
--}}
@props([
    'teleport' => false,
])

@php
    use Filament\Actions\Action;
    use Illuminate\Support\Arr;

    $currentTenant = filament()->getTenant();
    $currentTenantName = filament()->getTenantName($currentTenant);

    $items = $this->getTenantMenuItems();

    $tenants = $this->getSwitchableTenants();
    $canSwitchTenants = filled($tenants);

    $isSearchable = $canSwitchTenants && (filament()->isTenantMenuSearchable() ?? (count($tenants) >= 10));

    $itemsBeforeAndAfterTenantSwitcher = collect($items)
        ->groupBy(fn (Action $item): bool => $canSwitchTenants && ($item->getSort() < 0), preserveKeys: true)
        ->all();
    $itemsBeforeTenantSwitcher = $itemsBeforeAndAfterTenantSwitcher[true] ?? collect();
    $itemsAfterTenantSwitcher = $itemsBeforeAndAfterTenantSwitcher[false] ?? collect();

    $multiGroupAfterSwitcher = $this->hasMultipleTenantMenuItemGroups();
    $afterSwitcherItemGroups = $multiGroupAfterSwitcher ? $this->getTenantMenuItemGroupsAfterSwitcher() : [];

    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
@endphp

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TENANT_MENU_BEFORE) }}

<x-filament::dropdown
    placement="bottom-start"
    size
    :teleport="$teleport"
    :attributes="
        \Filament\Support\prepare_inherited_attributes($attributes)
            ->class(['fi-tenant-menu'])
    "
>
    <x-slot name="trigger">
        <button
            @if ($isSidebarCollapsibleOnDesktop)
                x-data="{ tooltip: false }"
                x-effect="
                    tooltip = $store.sidebar.isOpen
                        ? false
                        : {
                              content: @js($currentTenantName),
                              placement: document.dir === 'rtl' ? 'left' : 'right',
                              theme: $store.theme,
                          }
                "
                x-tooltip.html="tooltip"
            @endif
            type="button"
            class="fi-tenant-menu-trigger"
        >
            <x-filament-panels::avatar.tenant
                :tenant="$currentTenant"
                loading="lazy"
            />

            <span
                @if ($isSidebarCollapsibleOnDesktop)
                    x-show="$store.sidebar.isOpen"
                    x-transition:enter="fi-transition-enter"
                    x-transition:enter-start="fi-transition-enter-start"
                    x-transition:enter-end="fi-transition-enter-end"
                    x-transition:leave="fi-transition-leave"
                    x-transition:leave-start="fi-transition-leave-start"
                    x-transition:leave-end="fi-transition-leave-end"
                @endif
                class="fi-tenant-menu-trigger-text"
            >
                @if ($currentTenant instanceof \Filament\Models\Contracts\HasCurrentTenantLabel)
                    <span class="fi-tenant-menu-trigger-current-tenant-label">
                        {{ $currentTenant->getCurrentTenantLabel() }}
                    </span>
                @endif

                <span class="fi-tenant-menu-trigger-tenant-name">
                    {{ $currentTenantName }}
                </span>
            </span>

            {{
                \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::ChevronDown, alias: \Filament\View\PanelsIconAlias::TENANT_MENU_TOGGLE_BUTTON, attributes: new \Filament\Support\View\ComponentAttributeBag([
                    'x-show' => $isSidebarCollapsibleOnDesktop ? '$store.sidebar.isOpen' : null,
                    'x-transition:enter' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-enter' : null,
                    'x-transition:enter-start' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-enter-start' : null,
                    'x-transition:enter-end' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-enter-end' : null,
                    'x-transition:leave' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-leave' : null,
                    'x-transition:leave-start' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-leave-start' : null,
                    'x-transition:leave-end' => $isSidebarCollapsibleOnDesktop ? 'fi-transition-leave-end' : null,
                ]))
            }}
        </button>
    </x-slot>

    @if ($itemsBeforeTenantSwitcher->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsBeforeTenantSwitcher as $item)
                {{ $item }}
            @endforeach
        </x-filament::dropdown.list>
    @endif

    @if ($canSwitchTenants)
        <div x-data="{ search: '' }">
            <x-filament::dropdown.list>
                @if ($isSearchable)
                    <div x-id="['input']">
                        <label x-bind:for="$id('input')" class="fi-sr-only">
                            {{ __('filament-panels::layout.tenant_menu.search_field.label') }}
                        </label>

                        <x-filament::input
                            x-bind:id="$id('input')"
                            x-model="search"
                            :placeholder="__('filament-panels::layout.tenant_menu.search_field.placeholder')"
                            type="search"
                        />
                    </div>
                @endif

                @foreach ($tenants as $tenant)
                    @php
                        $tenantImage = filament()->getTenantAvatarUrl($tenant);
                        $tenantName = filament()->getTenantName($tenant);
                        $tenantUrl = filament()->getUrl($tenant);
                    @endphp

                    <div
                        x-show="
                            search === '' ||
                                @js($tenantName).replace(/ /g, '')
                                    .toLowerCase()
                                    .includes(search.replace(/ /g, '').toLowerCase())
                        "
                    >
                        <x-filament::dropdown.list.item
                            :href="$tenantUrl"
                            :image="$tenantImage"
                            tag="a"
                        >
                            {{ $tenantName }}
                        </x-filament::dropdown.list.item>
                    </div>
                @endforeach
            </x-filament::dropdown.list>
        </div>
    @endif

    @if ($multiGroupAfterSwitcher && $afterSwitcherItemGroups !== [])
        @foreach ($afterSwitcherItemGroups as $afterSwitcherGroup)
            <x-filament::dropdown.list>
                @foreach ($afterSwitcherGroup as $item)
                    {{ $item }}
                @endforeach
            </x-filament::dropdown.list>
        @endforeach
    @elseif ($itemsAfterTenantSwitcher->isNotEmpty())
        <x-filament::dropdown.list>
            @foreach ($itemsAfterTenantSwitcher as $item)
                {{ $item }}
            @endforeach
        </x-filament::dropdown.list>
    @endif
</x-filament::dropdown>

{{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TENANT_MENU_AFTER) }}
