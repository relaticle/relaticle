<button
    type="button"
    class="fi-record-details-toggle"
    x-on:click="showAllDetails = ! showAllDetails"
    x-bind:aria-expanded="showAllDetails"
>
    <span x-show="! showAllDetails">{{ __('filament/record-page.view_all') }}</span>
    <span x-show="showAllDetails" x-cloak>{{ __('filament/record-page.show_less') }}</span>
</button>
