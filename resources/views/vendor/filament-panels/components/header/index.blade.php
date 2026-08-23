@props([
    'actions' => [],
    'actionsAlignment' => null,
    'breadcrumbs' => [],
    'heading' => null,
    'subheading' => null,
])

@php
    $isAppPanel = filament()->getId() === 'app';
    $beforeHeading = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, scopes: $this->getRenderHookScopes());
    $afterHeading = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, scopes: $this->getRenderHookScopes());
    $beforeActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $this->getRenderHookScopes());
    $afterActions = \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_ACTIONS_AFTER, scopes: $this->getRenderHookScopes());
    $headingEnd = $isAppPanel && method_exists($this, 'getHeadingEnd') ? $this->getHeadingEnd() : null;
    $hasInlineHeaderContent = $breadcrumbs
        || filled($beforeHeading)
        || (! $isAppPanel && filled($heading))
        || filled($afterHeading)
        || filled($subheading);
    $hasHeaderActions = filled($beforeActions) || $actions || filled($afterActions);
@endphp

<header
    {{
        $attributes->class([
            'fi-header',
            'fi-header-has-breadcrumbs' => $breadcrumbs,
            'fi-header-page-heading-only' => $isAppPanel && filled($heading) && ! $hasInlineHeaderContent && ! $hasHeaderActions,
        ])
    }}
>
    @if ($isAppPanel && filled($heading))
        <template x-teleport=".fi-topbar-start">
            <div
                data-page-heading
                class="fi-topbar-page-heading"
                title="{{ str(strip_tags((string) $heading))->squish() }}"
            >
                <h1 class="fi-topbar-page-title">{{ $heading }}</h1>

                @if (filled($headingEnd))
                    {{ $headingEnd }}
                @endif
            </div>
        </template>
    @endif

    @if ($hasInlineHeaderContent)
        <div>
            @if ($breadcrumbs)
                <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
            @endif

            {{ $beforeHeading }}

            @if (! $isAppPanel && filled($heading))
                <h1 class="fi-header-heading">
                    {{ $heading }}
                </h1>
            @endif

            {{ $afterHeading }}

            @if (filled($subheading))
                <p @class(['fi-header-subheading', 'mt-0!' => $isAppPanel])>
                    {{ $subheading }}
                </p>
            @endif
        </div>
    @endif

    @if ($hasHeaderActions)
        <div @class(['fi-header-actions-ctn', 'ms-auto' => $isAppPanel && ! $hasInlineHeaderContent])>
            {{ $beforeActions }}

            @if ($actions)
                <x-filament::actions
                    :actions="$actions"
                    :alignment="$actionsAlignment"
                />
            @endif

            {{ $afterActions }}
        </div>
    @endif
</header>
