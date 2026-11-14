@php
    $capabilities = [
        [__('Custom attributes'), __('Add the attributes your business tracks to any record, and link related records together.')],
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

    <ul class="bg-rail grid gap-px">
        @foreach($capabilities as [$title, $description])
            <li class="bg-surface grid grid-cols-1 gap-x-10 gap-y-1.5 px-6 py-6 md:grid-cols-12 md:items-baseline md:px-10">
                <h3 class="text-base font-medium text-gray-950 md:col-span-4 dark:text-white">{{ $title }}</h3>
                <p class="type-prose text-gray-500 md:col-span-8 dark:text-gray-400">{{ $description }}</p>
            </li>
        @endforeach

        <li class="bg-tint grid grid-cols-1 items-center gap-x-10 gap-y-4 px-6 py-6 md:grid-cols-12 md:px-10">
            <p class="text-base font-medium text-gray-950 md:col-span-4 dark:text-white">{{ __('Try every part of it.') }}</p>
            <p class="type-prose text-gray-700 md:col-span-5 dark:text-gray-300">{{ __(':days-day Cloud Pro trial, no card, unlimited users.', ['days' => \App\Models\Workspace::PRO_TRIAL_DAYS]) }}</p>
            <div class="md:col-span-3 md:justify-self-end">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="capabilities">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
            </div>
        </li>
    </ul>
</x-cloud::marketing.frame>
