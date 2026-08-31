{{--
    Collapses and expands the app panel's sidebar. Rendered into
    `TENANT_MENU_AFTER` so it sits in the workspace switcher's row when the
    sidebar is open, and directly beneath the workspace avatar on the collapsed
    rail. `.fi-sidebar-toggle-btn` holds it a fixed distance from the panel's
    trailing edge in both states, so it rides that edge as the width animates.
--}}
<button
    type="button"
    data-sidebar-workspace-toggle
    aria-controls="fi-main-sidebar"
    x-data="{
        get isOpen() {
            return $store.sidebar.isOpen
        },
        get label() {
            return this.isOpen
                ? @js(__('filament-panels::layout.actions.sidebar.collapse.label'))
                : @js(__('filament-panels::layout.actions.sidebar.expand.label'))
        },
        get shortcut() {
            return (navigator.userAgentData?.platform ?? navigator.platform ?? '')
                .toLowerCase()
                .includes('mac')
                ? '⌘\\'
                : 'Ctrl+\\'
        },
        toggle() {
            this.isOpen ? $store.sidebar.close() : $store.sidebar.open()
        },
    }"
    x-on:click="toggle()"
    x-on:keydown.window="
        if ((event.metaKey || event.ctrlKey) && event.key === '\\' && ! event.repeat) {
            event.preventDefault()
            toggle()
        }
    "
    x-bind:aria-expanded="isOpen"
    x-bind:aria-label="label"
    x-tooltip="{
        content: `${label} &nbsp; ${shortcut}`,
        placement: document.dir === 'rtl' ? 'left' : 'right',
        theme: $store.theme,
    }"
    class="fi-sidebar-toggle-btn"
>
    <x-ri-sidebar-fold-line class="fi-sidebar-toggle-icon fi-sidebar-toggle-icon-collapse" aria-hidden="true" />
    <x-ri-sidebar-unfold-line class="fi-sidebar-toggle-icon fi-sidebar-toggle-icon-expand" aria-hidden="true" />
</button>
