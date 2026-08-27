@php
    use Filament\Support\Enums\Width;

    $livewire ??= null;

    $hasTopbar = filament()->hasTopbar();
    $isSidebarCollapsibleOnDesktop = filament()->isSidebarCollapsibleOnDesktop();
    $isSidebarFullyCollapsibleOnDesktop = filament()->isSidebarFullyCollapsibleOnDesktop();
    $hasTopNavigation = filament()->hasTopNavigation();
    $hasNavigation = filament()->hasNavigation();
    $renderHookScopes = $livewire?->getRenderHookScopes();
    $maxContentWidth ??= (filament()->getMaxContentWidth() ?? Width::SevenExtraLarge);

    if (is_string($maxContentWidth)) {
        $maxContentWidth = Width::tryFrom($maxContentWidth) ?? $maxContentWidth;
    }

    $isAppPanel = filament()->getId() === 'app';
@endphp

<x-filament-panels::layout.base
    :livewire="$livewire"
    @class([
        'fi-body-has-navigation' => $hasNavigation,
        'fi-body-has-sidebar-collapsible-on-desktop' => $isSidebarCollapsibleOnDesktop,
        'fi-body-has-sidebar-fully-collapsible-on-desktop' => $isSidebarFullyCollapsibleOnDesktop,
        'fi-body-has-topbar' => $hasTopbar,
        'fi-body-has-top-navigation' => $hasTopNavigation,
    ])
>
    @if ($isAppPanel)
        {{-- Custom layout structure with full height sidebar and topbar for App panel --}}
        <div class="fi-app-layout">
            {{-- Sidebar overlay for mobile --}}
            @if ($hasNavigation)
                <div
                    x-cloak
                    x-data="{}"
                    x-on:click="$store.sidebar.close()"
                    x-show="$store.sidebar.isOpen"
                    x-transition.opacity.300ms
                    class="fi-sidebar-close-overlay"
                    style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 29;"
                    @media (min-width: 1024px) {
                        style="display: none;"
                    }
                ></div>

                {{-- Sidebar - Keep all original Alpine.js functionality --}}
                @livewire(filament()->getSidebarLivewireComponent())
            @endif

            {{-- Main content wrapper --}}
            <div class="fi-app-main-wrapper">
                {{-- Topbar --}}
                @if ($hasTopbar)
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

                    @livewire(filament()->getTopbarLivewireComponent())

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
                @endif

                {{-- Page content area --}}
                <div class="fi-layout" style="flex: 1; overflow-y: auto;">
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_START, scopes: $renderHookScopes) }}

                    <div
                        @if ($isSidebarCollapsibleOnDesktop)
                            x-data="{}"
                            x-bind:class="{
                                'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                            }"
                            x-bind:style="'display: flex; opacity:1;'"
                            {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                        @elseif ($isSidebarFullyCollapsibleOnDesktop)
                            x-data="{}"
                            x-bind:class="{
                                'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                            }"
                            x-bind:style="'display: flex; opacity:1;'"
                            {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                        @elseif (! ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop || $hasTopNavigation || (! $hasNavigation)))
                            x-data="{}"
                            x-bind:style="'display: flex; opacity:1;'" {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                        @endif
                        class="fi-main-ctn"
                        style="min-height: 100%;"
                    >
                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_BEFORE, scopes: $renderHookScopes) }}

                        <main
                            @class([
                                'fi-main',
                                ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                            ])
                        >
                            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                            {{ $slot }}

                            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                        </main>

                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_AFTER, scopes: $renderHookScopes) }}

                        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
                    </div>

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}
                </div>
            </div>
        </div>
    @else
        {{-- Default Filament layout for other panels (e.g., sysadmin) --}}
        @if ($hasTopbar)
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_BEFORE, scopes: $renderHookScopes) }}

            @livewire(filament()->getTopbarLivewireComponent())

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::TOPBAR_AFTER, scopes: $renderHookScopes) }}
        @elseif ($hasNavigation)
            <div
                @if ($isSidebarFullyCollapsibleOnDesktop)
                    x-data="{}"
                    x-bind:class="{ 'lg:fi-hidden': $store.sidebar.isOpen }"
                @endif
                @class([
                    'fi-layout-sidebar-toggle-btn-ctn',
                    'lg:fi-hidden' => ! $isSidebarFullyCollapsibleOnDesktop,
                ])
            >
                <x-filament::icon-button
                    color="gray"
                    :icon="\Filament\Support\Icons\Heroicon::OutlinedBars3"
                    :icon-alias="\Filament\View\PanelsIconAlias::SIDEBAR_EXPAND_BUTTON"
                    icon-size="lg"
                    :label="__('filament-panels::layout.actions.sidebar.expand.label')"
                    x-cloak
                    x-data="{}"
                    x-on:click="$store.sidebar.open()"
                    class="fi-layout-sidebar-toggle-btn"
                />
            </div>
        @endif

        <div class="fi-layout">
            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_START, scopes: $renderHookScopes) }}

            @if ($hasNavigation)
                <div
                    x-cloak
                    x-data="{}"
                    x-on:click="$store.sidebar.close()"
                    x-show="$store.sidebar.isOpen"
                    x-transition.opacity.300ms
                    class="fi-sidebar-close-overlay"
                ></div>

                @livewire(filament()->getSidebarLivewireComponent())
            @endif

            <div
                @if ($isSidebarCollapsibleOnDesktop)
                    x-data="{}"
                    x-bind:class="{
                        'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                    }"
                    x-bind:style="'display: flex; opacity:1;'"
                    {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                @elseif ($isSidebarFullyCollapsibleOnDesktop)
                    x-data="{}"
                    x-bind:class="{
                        'fi-main-ctn-sidebar-open': $store.sidebar.isOpen,
                    }"
                    x-bind:style="'display: flex; opacity:1;'"
                    {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                @elseif (! ($isSidebarCollapsibleOnDesktop || $isSidebarFullyCollapsibleOnDesktop || $hasTopNavigation || (! $hasNavigation)))
                    x-data="{}"
                    x-bind:style="'display: flex; opacity:1;'" {{-- Mimics `x-cloak`, as using `x-cloak` causes visual issues with chart widgets --}}
                @endif
                class="fi-main-ctn"
            >
                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_BEFORE, scopes: $renderHookScopes) }}

                <main
                    @class([
                        'fi-main',
                        ($maxContentWidth instanceof Width) ? "fi-width-{$maxContentWidth->value}" : $maxContentWidth,
                    ])
                >
                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_START, scopes: $renderHookScopes) }}

                    {{ $slot }}

                    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_END, scopes: $renderHookScopes) }}
                </main>

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::CONTENT_AFTER, scopes: $renderHookScopes) }}

                {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $renderHookScopes) }}
            </div>

            {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}
        </div>
    @endif

    {{-- Keeps the topbar showing exactly the heading the current page rendered.

         The heading is teleported into the Topbar Livewire component (see
         filament.app.topbar-page-heading), whose morph removes any child it did
         not itself render, so without this a topbar re-render leaves the page
         with no heading at all. It reads the current page's template on every
         pass rather than remembering the node: a remembered node survives the
         cleanup Alpine does on navigation and follows you onto the next page. --}}
    <script>
        (() => {
            const sync = () => {
                const target = document.querySelector('.fi-topbar-start');

                if (! target) {
                    return;
                }

                const source = document.querySelector('template[data-page-heading-source]');
                const heading = source?._x_teleport ?? null;

                // Drop anything the current page did not render, which is how a
                // previous page's title stops following you around.
                target.querySelectorAll('[data-page-heading]').forEach((node) => {
                    if (node !== heading) {
                        node.remove();
                    }
                });

                if (heading && heading.parentElement !== target) {
                    target.append(heading);
                }
            };

            document.addEventListener('livewire:navigated', sync);

            // livewire:init, not a bare call: this script is in the layout, which
            // parses before Livewire has defined its hook API.
            document.addEventListener('livewire:init', () => {
                window.Livewire.hook('morphed', () => queueMicrotask(sync));
            });
        })();
    </script>
</x-filament-panels::layout.base>
