<div class="p-4">
    <livewire:activity-log-list
        :subject-class="get_class($owner)"
        :subject-key="$owner->getKey()"
        :per-page="$perPage"
        :group-by-date="$groupByDate"
        :key="'activity-log-rm-'.get_class($owner).'-'.$owner->getKey()"
    />
</div>
