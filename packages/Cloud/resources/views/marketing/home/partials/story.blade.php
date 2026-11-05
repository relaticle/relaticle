@php
    $assistantName = (string) config('chat.assistant_name');
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $steps = array_values(array_filter([
        $emailIntegrationActive ? ['mailbox', __('Mon 9:12'), __('Adds Lena Ortiz and Northwind after your first email.')] : null,
        ['you', __('Tue 14:30'), __('Moves Northwind renewal to Negotiation on the board.')],
        ['claude', __('Wed 10:05'), __('Creates a follow-up task for Thursday from a chat in Claude.')],
        ['rela', __('Fri 16:48'), __('Marks the opportunity as won after you approve the change.')],
    ]));

    $slots = [
        ['lg:col-start-1 lg:row-start-1', 'lg:col-start-2 lg:row-start-1', 'right'],
        ['lg:col-start-1 lg:row-start-2', 'lg:col-start-2 lg:row-start-2', 'right'],
        ['lg:col-start-5 lg:row-start-1', 'lg:col-start-4 lg:row-start-1', 'left'],
        ['lg:col-start-5 lg:row-start-2', 'lg:col-start-4 lg:row-start-2', 'left'],
    ];
@endphp

<x-cloud::marketing.frame data-story>
    <x-cloud::marketing.frame-header :label="__('One record')" :lead="$emailIntegrationActive ? __('Four hands, one opportunity.') : __('Three hands, one opportunity.')">
        {{ $emailIntegrationActive
            ? __('Your inbox, you, your agent and :name all work on the same record.', ['name' => $assistantName])
            : __('You, your agent and :name all work on the same record.', ['name' => $assistantName]) }}
    </x-cloud::marketing.frame-header>

    <div class="bg-tint bg-dots px-6 py-12 md:px-10 md:py-20">
        <div class="mx-auto grid grid-cols-1 items-center gap-4 lg:grid-cols-[minmax(0,1fr)_4rem_minmax(0,27rem)_4rem_minmax(0,1fr)] lg:grid-rows-2 lg:gap-x-0 lg:gap-y-12">
            <div class="order-first flex justify-center lg:order-none lg:col-start-3 lg:row-span-2 lg:row-start-1">
                @include('cloud::marketing.home.partials.story-record', ['cardClass' => 'w-full max-w-md'])
            </div>

            @foreach($steps as $index => [$actor, $time, $action])
                @php([$nodeSlot, $wireSlot, $travel] = $slots[$index])

                <div data-story-step data-story-actor="{{ $actor }}" class="{{ $nodeSlot }} rounded-xl border border-gray-200 bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.04)] dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-3">
                        <x-cloud::marketing.actor :as="$actor"/>
                        <span class="type-label text-gray-500 dark:text-gray-400">{{ $time }}</span>
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $action }}</p>
                    @if($actor === 'rela')
                        <p class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                            <x-ri-checkbox-circle-fill class="h-4 w-4"/>
                            {{ __('Approved by you') }}
                        </p>
                    @endif
                </div>

                <div data-story-wire="{{ $actor }}" data-story-travel="{{ $travel }}" class="{{ $wireSlot }} relative hidden h-px lg:block" aria-hidden="true">
                    <span class="absolute inset-0 border-t border-dashed border-gray-400 dark:border-white/30"></span>
                    <span class="absolute -left-1 -top-1 h-2 w-2 rounded-full border border-gray-400 bg-white dark:border-white/30 dark:bg-gray-900"></span>
                    <span class="absolute -right-1 -top-1 h-2 w-2 rounded-full border border-gray-400 bg-white dark:border-white/30 dark:bg-gray-900"></span>
                    <span data-story-pulse class="absolute -top-[3px] left-0 h-1.5 w-1.5 rounded-full bg-primary-500 opacity-0"></span>
                </div>
            @endforeach
        </div>
    </div>
</x-cloud::marketing.frame>
