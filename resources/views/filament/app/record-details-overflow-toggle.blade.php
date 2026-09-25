<button
    type="button"
    class="fi-record-details-overflow-toggle"
    x-cloak
    x-show="hasOverflow"
    wire:click="toggleRecordDetails"
>
    {{ $expanded ? __('filament/inline-edit.hide') : __('filament/inline-edit.view_more') }}
</button>
