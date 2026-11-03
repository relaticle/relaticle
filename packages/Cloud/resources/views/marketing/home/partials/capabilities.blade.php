@php
    $capabilities = [
        [__('Custom fields'), __('Add the fields your business tracks to any record, and link related records together.')],
        [__('Companies and people'), __('Each company keeps its people, opportunities and history in one place.')],
        [__('Tasks'), __('Assign follow-ups, set due dates, and link them to the records they concern.')],
        [__('Notes and activity'), __('Write notes on the record, and review the activity history to see what changed.')],
        [__('Workspaces and roles'), __('Keep teams in separate workspaces and use roles to control who can change data.')],
        [__('Import and export'), __('Bring records in from a CSV, map the columns, and export your data any time.')],
    ];
@endphp

<x-cloud::marketing.frame>
    <x-cloud::marketing.frame-header :label="__('And the rest')" :lead="__('Everything else a CRM needs.')">
        {{ __('Simple enough that your team keeps it current.') }}
    </x-cloud::marketing.frame-header>

    <div class="grid grid-cols-1 gap-x-10 gap-y-10 px-6 py-12 sm:grid-cols-2 md:px-10 md:py-16 lg:grid-cols-3">
        @foreach($capabilities as [$title, $description])
            <div class="border-rail border-t pt-5">
                <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $title }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $description }}</p>
            </div>
        @endforeach
    </div>
</x-cloud::marketing.frame>
