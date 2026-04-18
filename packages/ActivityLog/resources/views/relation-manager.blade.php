<div class="p-4">
    @livewire('activity-log-list', [
        'subjectClass' => get_class($owner),
        'subjectKey' => $owner->getKey(),
        'perPage' => $perPage,
        'groupByDate' => $groupByDate,
    ], key('activity-log-rm-'.get_class($owner).'-'.$owner->getKey()))
</div>
