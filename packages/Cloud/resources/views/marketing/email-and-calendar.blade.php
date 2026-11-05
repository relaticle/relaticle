@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);

    $title = __('Email and calendar sync for your CRM').' - Relaticle';
    $description = __('Connect Gmail or Outlook to Relaticle. Email and meetings land on the people, companies and opportunities they involve, and you decide what your team sees.');

    $steps = [
        [__('Connect your inbox'), __('Link a Google or Microsoft account from your workspace settings. Your mailbox and calendar import in the background.')],
        [__('Records fill in'), __('People you email are added for you, with their companies. Each email and meeting lands on the record it involves.')],
        [__('You choose who sees what'), __('Pick how much of each mailbox your workspace sees, and change it for any single email.')],
    ];

    $sharingLevels = [
        [__('Private'), __('Nothing. Only you see the email.'), false],
        [__('Participants only'), __('Who took part, and when.'), true],
        [__('Subject line and participants'), __('The subject line, who took part, and when.'), false],
        [__('Full access'), __('The body, the subject and the attachments.'), false],
    ];

    $privacyControls = [
        [__('Change one email'), __('Open an email on a record and set who can see it, without touching the rest of the mailbox.')],
        [__('Request access'), __('A teammate who needs more asks for it. You approve or deny, one email at a time.')],
        [__('Hide addresses and domains'), __('Owners and admins hide email and meetings that involve chosen addresses or domains, everywhere in Relaticle.')],
    ];

    $meetingPoints = [
        [__('Your day on Home'), __('Today\'s meetings from every connected calendar, in one list.')],
        [__('On every record'), __('Each person, company and opportunity shows the meetings that involve it.')],
        [__('Answer invitations'), __('Accept, decline or answer maybe, and your calendar updates.')],
    ];

    $faqs = array_values(array_filter([
        [
            __('Which accounts can I connect?'),
            __('Google accounts, such as Gmail, and Microsoft accounts, such as Outlook and Microsoft 365. Each member connects their own mailbox.'),
        ],
        [
            __('Does Relaticle change anything in my mailbox?'),
            __('No. Relaticle never changes, moves or deletes a message, and never imports your drafts. On your calendar, it changes only your own answer to an invitation.'),
        ],
        [
            __('Who can see my email?'),
            __('You always see your own email in full. A new workspace shows teammates only who you talked to and when. You can change that for each mailbox and each email, and full access is never the workspace default.'),
        ],
        [
            __('Will newsletters and cold email fill up my CRM?'),
            __('No. By default, Relaticle adds a person only when someone in your workspace emailed them or met them. Free mail domains such as gmail.com never create a company, and no-reply senders never create a record.'),
        ],
        [
            __('Can :name send email for me?', ['name' => $assistantName]),
            __(':name drafts replies and proposes emails on a card that shows every recipient and the full message. Nothing is sent until you click Send, and you get a few seconds to undo it.', ['name' => $assistantName]),
        ],
        $billingActive ? [
            __('How many mailboxes can I connect?'),
            __('The Free plan connects :free, Cloud Pro up to :pro, and Cloud Scale up to :scale. Enterprise has no limit.', [
                'free' => trans_choice('{1} one mailbox|[2,*] :count mailboxes', \App\Enums\Plan::Free->connectedMailboxes()),
                'pro' => \App\Enums\Plan::Pro->connectedMailboxes(),
                'scale' => \App\Enums\Plan::Scale->connectedMailboxes(),
            ]),
        ] : null,
        [
            __('Does it work when I self-host?'),
            __('Yes. A self-hosted install connects mailboxes through its own Google and Microsoft app credentials, which the self-hosting guide explains.'),
        ],
    ]));
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
>
    @push('header')
        @vite('packages/Cloud/resources/js/motion.js')
    @endpush

    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Email and calendar') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('Your inbox fills in your CRM.') }}
            <span class="text-gray-500 dark:text-gray-400">{{ __('Nothing to type.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('Connect Gmail or Outlook. Every email and meeting lands on the person, company and opportunity it involves, and you decide what your team sees.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#sharing">
                    {{ __('How sharing works') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No credit card. Unlimited users. Open source.') }}</p>
        </x-slot:aside>

        @include('cloud::marketing.partials.email-calendar-demo')
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame>
        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($steps as $index => [$stepTitle, $stepDescription])
                <div class="bg-surface p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h2 class="text-lg font-medium text-gray-950 dark:text-white">{{ $stepTitle }}</h2>
                    <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $stepDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="sharing">
        <x-cloud::marketing.frame-header :label="__('Sharing')" :lead="__('Share what helps the team.')">
            {{ __('Keep the rest private.') }}
        </x-cloud::marketing.frame-header>

        <div class="border-rail bg-rail grid grid-cols-1 gap-px border-b sm:grid-cols-2 lg:grid-cols-4">
            @foreach($sharingLevels as $index => [$levelName, $levelDescription, $isDefault])
                <div class="bg-surface p-6 md:p-8">
                    <div class="mb-6 flex gap-1" aria-hidden="true">
                        @foreach(range(0, 2) as $bar)
                            <span @class(['h-1.5 w-6 rounded-full', $bar < $index ? 'bg-primary dark:bg-primary-400' : 'bg-rail'])></span>
                        @endforeach
                    </div>
                    <h3 class="flex flex-wrap items-center gap-2 text-base font-medium text-gray-950 dark:text-white">
                        {{ $levelName }}
                        @if($isDefault)
                            <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-micro font-medium text-gray-600 dark:bg-white/[0.08] dark:text-gray-300">{{ __('Default') }}</span>
                        @endif
                    </h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $levelDescription }}</p>
                </div>
            @endforeach
        </div>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($privacyControls as [$controlTitle, $controlDescription])
                <div class="bg-surface p-6 md:p-8">
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $controlTitle }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $controlDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Work from the record')" :lead="__('Reply where the context is.')">
            {{ __('Then let :name take the first draft.', ['name' => $assistantName]) }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid gap-px">
            <x-cloud::marketing.showcase :label="__('01 · Send')" :lead="__('Reply without switching tabs.')">
                {{ __('Reply, forward or write a new email from any person, company or opportunity, with templates, signatures and merge tags.') }}

                <x-slot:visual>
                    <div class="flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                        <x-cloud::marketing.window class="w-full max-w-lg">
                            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('New email') }}</p>
                                <x-ri-close-line class="h-4 w-4 text-gray-400"/>
                            </div>
                            <dl class="divide-y divide-gray-100 text-sm dark:divide-white/[0.06]">
                                @foreach([
                                    [__('From'), 'Sam Patel'],
                                    [__('To'), 'Lena Ortiz'],
                                    [__('Subject'), __('Contract for the 2027 renewal')],
                                ] as [$field, $value])
                                    <div class="flex gap-3 px-4 py-2.5">
                                        <dt class="w-16 shrink-0 text-gray-400 dark:text-gray-500">{{ $field }}</dt>
                                        <dd class="min-w-0 truncate text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <div class="border-t border-gray-100 px-4 py-4 text-sm leading-relaxed text-gray-700 dark:border-white/[0.06] dark:text-gray-300">
                                <p>{{ __('Hi Lena,') }}</p>
                                <p class="mt-2">{{ __('The contract for') }} <span class="rounded bg-primary-50 px-1 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/15 dark:text-primary-300">{{ __('Company') }}</span> {{ __('is attached. Does Thursday still work for the signature?') }}</p>
                            </div>
                            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                                <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="inline-flex items-center gap-1"><x-ri-attachment-2 class="h-3.5 w-3.5"/>{{ __('Attach') }}</span>
                                    <span class="hidden items-center gap-1 sm:inline-flex"><x-ri-quill-pen-line class="h-3.5 w-3.5"/>{{ __('Signature') }}</span>
                                    <span class="inline-flex items-center gap-1"><x-ri-file-copy-2-line class="h-3.5 w-3.5"/>{{ __('Template') }}</span>
                                </div>
                                <span class="inline-flex h-7 items-center rounded-md bg-primary-600 px-3 text-xs font-medium text-white shadow-sm">{{ __('Send email') }}</span>
                            </div>
                        </x-cloud::marketing.window>
                    </div>
                </x-slot:visual>
            </x-cloud::marketing.showcase>

            <x-cloud::marketing.showcase
                :label="__('02 · :name', ['name' => $assistantName])"
                :lead="__(':name drafts. You send.', ['name' => $assistantName])"
                :link-label="__('Meet :name', ['name' => $assistantName])"
                :link-href="route('ai')"
            >
                {{ __(':name, the built-in AI assistant, puts each email on its own card. Nothing leaves until you click Send.', ['name' => $assistantName]) }}

                <x-slot:visual>
                    <div class="flex min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12">
                        <div class="w-full max-w-md space-y-3">
                            <div class="flex justify-end">
                                <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-900 px-3.5 py-2.5 text-sm leading-relaxed text-white dark:bg-white/10 dark:text-gray-100">
                                    {{ __('Email Lena the contract and confirm Thursday') }}
                                </p>
                            </div>
                            <x-cloud::marketing.window class="ring-[3px] ring-primary-100 dark:ring-primary-400/10">
                                <p class="px-4 pt-3 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Send email') }}</p>
                                <p class="px-4 pb-3 pt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Contract for the 2027 renewal') }}</p>
                                <dl class="divide-y divide-gray-100 border-t border-gray-100 text-sm dark:divide-white/5 dark:border-white/5">
                                    <div class="flex gap-3 px-4 py-2.5">
                                        <dt class="w-12 shrink-0 text-gray-500 dark:text-gray-400">{{ __('To') }}</dt>
                                        <dd class="text-gray-900 dark:text-white">Lena Ortiz</dd>
                                    </div>
                                    <div class="px-4 py-2.5 text-gray-600 dark:text-gray-300">
                                        {{ __('Hi Lena, the contract is attached. Does Thursday still work for the signature?') }}
                                    </div>
                                </dl>
                                <div class="flex items-center justify-end gap-2 border-t border-gray-100 px-4 py-2.5 dark:border-white/5">
                                    <span class="inline-flex h-7 items-center rounded-md px-2.5 text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('Discard') }}</span>
                                    <span class="inline-flex h-7 items-center rounded-md bg-primary-600 px-2.5 text-xs font-medium text-white shadow-sm">{{ __('Send') }}</span>
                                </div>
                            </x-cloud::marketing.window>
                        </div>
                    </div>
                </x-slot:visual>
            </x-cloud::marketing.showcase>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Calendar')" :lead="__('Meetings land on the record too.')">
            {{ __('Walk into every call knowing the history.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($meetingPoints as [$pointTitle, $pointDescription])
                <div class="bg-surface p-6 md:p-8">
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $pointTitle }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $pointDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">
                        {{ __('Questions about email') }}
                    </h2>
                    @if($documentationActive)
                        <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            {{ __('Step-by-step guides live in the') }}
                            <a href="{{ url('/help/email-and-calendar') }}" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('help center') }}</a>.
                        </p>
                    @endif
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="email-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Your CRM, your rules.')" :continuation="__('Start free, self-host whenever you like.')"/>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($page) => $page
                ->name($title)
                ->description($description)
                ->url(url()->current()))
            ->fAQPage(fn ($page) => $page
                ->mainEntity(collect($faqs)->map(fn (array $faq) => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Email and calendar'))->item(route('emailAndCalendar')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
