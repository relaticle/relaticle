@php
    $capabilities = [
        ['ri-stack-line', __('Custom fields'), __('Add the fields your business tracks to any record, and link related records together.')],
        ['ri-building-2-line', __('Companies and people'), __('Each company keeps its people, opportunities and history in one place.')],
        ['ri-checkbox-circle-line', __('Tasks'), __('Assign follow-ups, set due dates, and link them to the records they concern.')],
        ['ri-quill-pen-line', __('Notes and activity'), __('Write notes on the record, and review the activity history to see what changed.')],
        ['ri-team-line', __('Workspaces and roles'), __('Keep teams in separate workspaces and use roles to control who can change data.')],
        ['ri-download-cloud-2-line', __('Import and export'), __('Bring records in from a CSV, map the columns, and export your data any time.')],
    ];
@endphp

<x-cloud::marketing.frame>
    <x-cloud::marketing.frame-header :label="__('And the rest')" :lead="__('Everything else a CRM needs.')">
        {{ __('Simple enough that your team keeps it current.') }}
    </x-cloud::marketing.frame-header>

    <div class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-2 lg:grid-cols-3">
        @foreach($capabilities as [$icon, $title, $description])
            <div class="bg-surface p-6 md:p-8">
                <x-dynamic-component :component="$icon" class="mb-5 h-5 w-5 text-gray-400 dark:text-gray-500"/>
                <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $title }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $description }}</p>
            </div>
        @endforeach
    </div>
</x-cloud::marketing.frame>
