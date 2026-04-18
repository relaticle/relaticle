<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @livewire('activity-log-list', [
        'subjectClass' => get_class($getRecord()),
        'subjectKey' => $getRecord()->getKey(),
        'perPage' => $getPerPage(),
        'groupByDate' => $isGrouped(),
        'emptyState' => $getEmptyStateMessage(),
    ], key('activity-log-'.get_class($getRecord()).'-'.$getRecord()->getKey()))
</x-dynamic-component>
