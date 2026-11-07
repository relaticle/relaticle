<x-resize-handle
    storage-key="sidebar-width"
    target="#fi-main-sidebar"
    :label="__('filament/panel.sidebar.resize')"
    x-on:resize-collapse="$store.sidebar.close()"
    x-on:resize-expand="$store.sidebar.open()"
    class="fi-sidebar-resize-handle"
/>
