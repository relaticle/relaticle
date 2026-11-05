@php
    $assistantName = (string) config('chat.assistant_name');
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $steps = array_values(array_filter([
        $emailIntegrationActive ? ['mailbox', __('Mon 9:12'), __('Adds Lena Ortiz and Northwind after your first email.')] : null,
        ['you', __('Tue 14:30'), __('Moves Northwind renewal to Negotiation on the board.')],
        ['claude', __('Wed 10:05'), __('Creates a follow-up task for Thursday from a chat in Claude.')],
        ['rela', __('Fri 16:48'), __('Marks the opportunity as won after you approve the change.')],
    ]));

    $dots = [
        'mailbox' => 'bg-emerald-500',
        'you' => 'bg-white',
        'claude' => 'bg-anthropic',
        'rela' => 'bg-primary-500',
    ];
@endphp

<x-cloud::marketing.frame class="spotlight dark" data-story-a>
    <div class="border-rail grid gap-8 border-b px-6 pb-12 pt-16 md:px-10 md:pb-14 md:pt-24 lg:grid-cols-12 lg:items-end lg:gap-10">
        <div class="lg:col-span-7">
            <x-cloud::marketing.eyebrow class="mb-5">{{ __('One record') }}</x-cloud::marketing.eyebrow>
            <h2 class="type-title text-white">{{ $emailIntegrationActive ? __('Four hands, one opportunity.') : __('Three hands, one opportunity.') }}</h2>
        </div>

        <div class="lg:col-span-5">
            <p class="type-prose text-gray-400">
                {{ $emailIntegrationActive
                    ? __('Your inbox, you, your agent and :name all work on the same record.', ['name' => $assistantName])
                    : __('You, your agent and :name all work on the same record.', ['name' => $assistantName]) }}
            </p>
            <div class="mt-5 flex flex-wrap gap-2" aria-hidden="true">
                @foreach($steps as [$actor])
                    <x-cloud::marketing.actor :as="$actor"/>
                @endforeach
            </div>
        </div>
    </div>

    <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
        <div class="bg-tint bg-dots feat-card relative lg:col-span-7">
            <div class="flex min-h-[26rem] items-center justify-center p-6 md:p-12">
                @include('cloud::marketing.home.partials.story-record')
            </div>
        </div>

        <div class="bg-surface p-6 md:p-10 lg:col-span-5">
            <ol class="relative space-y-7">
                @foreach($steps as [$actor, $time, $action])
                    <li class="relative pl-10">
                        @unless($loop->last)
                            <span class="absolute -bottom-7 left-[0.6875rem] top-7 w-px bg-white/10" aria-hidden="true"></span>
                        @endunless
                        <span class="bg-surface absolute left-0 top-0.5 flex h-6 w-6 items-center justify-center rounded-full ring-1 ring-white/15" aria-hidden="true">
                            <span class="{{ $dots[$actor] }} h-2 w-2 rounded-full"></span>
                        </span>

                        <div data-story-step data-story-actor="{{ $actor }}">
                            <div class="flex items-center justify-between gap-3">
                                <x-cloud::marketing.actor :as="$actor"/>
                                <span class="type-label text-gray-400">{{ $time }}</span>
                            </div>
                            <p class="mt-3 text-sm leading-relaxed text-gray-300">{{ $action }}</p>
                            @if($actor === 'rela')
                                <p class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-400">
                                    <x-ri-checkbox-circle-fill class="h-4 w-4"/>
                                    {{ __('Approved by you') }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
</x-cloud::marketing.frame>
