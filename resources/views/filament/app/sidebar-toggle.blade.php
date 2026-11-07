{{--
    The sidebar's collapse and expand controls. The `sidebar` placement sits in the
    workspace switcher's row and collapses the panel; the `topbar` placement sits at
    the start of the top bar and expands it again. Only one shows at a time, so no
    control travels across the panel while it animates.
--}}
@php
    $isSidebarPlacement = $placement === 'sidebar';
    $label = $isSidebarPlacement
        ? __('filament-panels::layout.actions.sidebar.collapse.label')
        : __('filament-panels::layout.actions.sidebar.expand.label');
@endphp

<button
    type="button"
    data-sidebar-toggle
    @if ($isSidebarPlacement)
        data-sidebar-workspace-toggle
    @else
        data-sidebar-expand-toggle
        x-cloak
        x-show="! $store.sidebar.isOpen"
        x-bind:class="{ 'fi-sidebar-collapsed': ! $store.sidebar.isOpen }"
        x-transition:enter="fi-transition-enter"
        x-transition:enter-start="fi-transition-enter-start"
        x-transition:enter-end="fi-transition-enter-end"
    @endif
    aria-controls="fi-main-sidebar"
    aria-label="{{ $label }}"
    x-data="{
        get shortcut() {
            return (navigator.userAgentData?.platform ?? navigator.platform ?? '')
                .toLowerCase()
                .includes('mac')
                ? '⌘\\'
                : 'Ctrl+\\'
        },
        toggle() {
            const hadFocus = document.activeElement === $el

            $store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()

            if (hadFocus) {
                $nextTick(() => requestAnimationFrame(() => {
                    [...document.querySelectorAll('[data-sidebar-toggle]')]
                        .find((button) => button !== $el)
                        ?.focus()
                }))
            }
        },
    }"
    x-on:click="toggle()"
    @if ($isSidebarPlacement)
        x-on:keydown.window="
            if ((event.metaKey || event.ctrlKey) && event.key === '\\' && ! event.repeat) {
                event.preventDefault()
                toggle()
            }
        "
    @endif
    x-bind:aria-expanded="$store.sidebar.isOpen"
    x-tooltip="{
        content: `${@js($label)} &nbsp; ${shortcut}`,
        placement: document.dir === 'rtl' ? 'left' : 'right',
        theme: $store.theme,
    }"
    @class([
        'fi-sidebar-toggle-btn' => $isSidebarPlacement,
        'fi-topbar-expand-sidebar-btn' => ! $isSidebarPlacement,
    ])
>
    @if ($isSidebarPlacement)
        <x-lucide-panel-left-close class="fi-sidebar-toggle-icon" aria-hidden="true" />
    @else
        <x-lucide-panel-left-open class="fi-sidebar-toggle-icon" aria-hidden="true" />
    @endif
</button>
