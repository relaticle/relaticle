<x-resize-handle
    storage-key="sidebar-width"
    target="#fi-main-sidebar"
    :label="__('filament/panel.sidebar.resize')"
    x-show="$store.sidebar.isOpen"
    x-cloak
    class="fi-sidebar-resize-handle"
/>
