<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <livewire:timeline-livewire
        :subject-class="get_class($getRecord())"
        :subject-key="$getRecord()->getKey()"
        :per-page="$getPerPage()"
        :group-by-date="$isGrouped()"
        :empty-state="$getEmptyStateMessage()"
        :infinite-scroll="$isInfiniteScroll()"
        :key="'timeline-'.get_class($getRecord()).'-'.$getRecord()->getKey()"
    />
</x-dynamic-component>
