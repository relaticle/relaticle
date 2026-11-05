@php
    $assistantName = (string) config('chat.assistant_name');
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $steps = array_values(array_filter([
        $emailIntegrationActive ? ['mailbox', __('Mailbox adds the people')] : null,
        ['you', __('You move the stage')],
        ['claude', __('Claude adds the task')],
        ['rela', __(':name closes it, you approve', ['name' => $assistantName])],
    ]));

    $pile = [
        ['ri-mail-line', __('Inbox'), __('Re: Renewal quote for 2027'), __('Still waiting on Lena.'), 'left-0 top-2 -rotate-6'],
        ['ri-file-excel-2-line', __('pipeline.xlsx'), __('Northwind renewal'), __('Last edited three weeks ago.'), 'right-0 top-0 rotate-3'],
        ['ri-alarm-line', __('Reminder'), __('Follow up with Lena?'), __('Snoozed twice.'), 'left-6 top-28 rotate-2'],
        ['ri-sticky-note-line', __('Notes'), __('Call with Northwind'), __('Which doc was it in?'), 'right-4 top-32 -rotate-3'],
        ['ri-calendar-line', __('Calendar'), __('Renewal call'), __('Friday 16:00, no agenda.'), 'left-16 top-52 rotate-1'],
    ];
@endphp

<x-cloud::marketing.frame data-story-c>
    <x-cloud::marketing.frame-header :label="__('One record')" :lead="__('A lot of chasing.')">
        {{ $emailIntegrationActive ? __('Or four hands on one record.') : __('Or three hands on one record.') }}
    </x-cloud::marketing.frame-header>

    <div class="grid grid-cols-1 gap-4 p-4 md:gap-6 md:p-10 lg:grid-cols-2">
        <div class="flex flex-col rounded-2xl bg-stone-100 p-4 sm:p-6 md:p-10 dark:bg-white/[0.04]">
            <p class="type-label text-center text-gray-500 dark:text-gray-400">{{ __('On your own') }}</p>
            <h3 class="type-lead mt-3 text-center text-gray-950 dark:text-white">{{ __('You do all of it.') }}</h3>

            <div class="relative mx-auto my-auto h-80 w-full max-w-md py-8" aria-hidden="true">
                @foreach($pile as [$pileIcon, $source, $title, $note, $place])
                    <div data-story-pile class="{{ $place }} absolute w-60 rounded-xl border border-gray-200 bg-white p-3.5 shadow-[0_8px_24px_-12px_rgba(0,0,0,0.2)] dark:border-white/10 dark:bg-gray-900">
                        <p class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <x-dynamic-component :component="$pileIcon" class="h-3.5 w-3.5"/>
                            {{ $source }}
                        </p>
                        <p class="mt-1.5 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</p>
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
                    </div>
                @endforeach
            </div>

            <p class="pt-6 text-center text-sm text-gray-600 dark:text-gray-400">{{ __('Tabs, threads and a spreadsheet. Every week.') }}</p>
        </div>

        <div class="flex flex-col rounded-2xl bg-primary-50 bg-[radial-gradient(60%_70%_at_80%_0%,var(--color-primary-100),transparent_70%)] p-4 sm:p-6 md:p-10 dark:bg-primary-500/[0.08] dark:bg-none">
            <p class="type-label text-center text-primary-700 dark:text-primary-300">{{ __('With Relaticle') }}</p>
            <h3 class="type-lead mt-3 text-center text-gray-950 dark:text-white">{{ $emailIntegrationActive ? __('Four hands do it.') : __('Three hands do it.') }}</h3>

            <div class="mt-8 flex justify-center">
                @include('cloud::marketing.home.partials.story-record', ['cardClass' => 'w-full max-w-md'])
            </div>

            <ol class="mx-auto mt-6 grid w-full max-w-md grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                @foreach($steps as $index => [$actor, $label])
                    <li data-story-step class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-xs font-medium text-primary-700 ring-1 ring-primary-200 dark:bg-gray-900 dark:text-primary-300 dark:ring-white/10">{{ $index + 1 }}</span>
                        {{ $label }}
                    </li>
                @endforeach
            </ol>

            <p class="mt-auto pt-6 text-center text-sm font-medium text-primary-700 dark:text-primary-300">{{ __('One record. Everyone writes to it.') }}</p>
        </div>
    </div>
</x-cloud::marketing.frame>
