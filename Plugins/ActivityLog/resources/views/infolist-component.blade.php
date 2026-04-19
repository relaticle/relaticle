<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <livewire:activity-log-list
        :subject-class="get_class($getRecord())"
        :subject-key="$getRecord()->getKey()"
        :per-page="$getPerPage()"
        :group-by-date="$isGrouped()"
        :empty-state="$getEmptyStateMessage()"
        :key="'activity-log-'.get_class($getRecord()).'-'.$getRecord()->getKey()"
    />
</x-dynamic-component>
