@if(filament()->getCurrentPanel()?->getId() === 'app')
    <button
        type="button"
        data-sidebar-workspace-toggle
        x-data="{}"
        x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-bind:aria-label="$store.sidebar.isOpen
            ? @js(__('filament-panels::layout.actions.sidebar.collapse.label'))
            : @js(__('filament-panels::layout.actions.sidebar.expand.label'))"
        x-bind:title="$store.sidebar.isOpen
            ? @js(__('filament-panels::layout.actions.sidebar.collapse.label'))
            : @js(__('filament-panels::layout.actions.sidebar.expand.label'))"
        x-bind:class="$store.sidebar.isOpen ? 'end-2' : 'end-1'"
        class="absolute top-4 z-20 hidden h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 lg:flex dark:hover:bg-gray-800 dark:hover:text-gray-200"
        aria-controls="fi-main-sidebar"
    >
        <x-ri-side-bar-line class="h-5 w-5" aria-hidden="true" />
    </button>
@endif
