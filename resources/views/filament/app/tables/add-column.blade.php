<span class="fi-ta-add-column">
    <button
        type="button"
        class="fi-ta-add-column-trigger"
        aria-haspopup="menu"
        aria-controls="add-column-menu"
        aria-expanded="false"
        data-add-column-trigger
        x-on:click="$dispatch('add-column-open', { trigger: $el })"
    >
        <x-filament::icon icon="lucide-plus" />
        <span>{{ __('filament/panel.tables.views.add_column') }}</span>
    </button>
</span>
