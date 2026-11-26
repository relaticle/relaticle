@use(Relaticle\Cloud\Marketing\Support\LocalizedUrl)
@php
    $assistantName = (string) config('chat.assistant_name');
    $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;

    $title = __('CRM for a cleaning business: quote to contract').' - Relaticle';
    $description = __(
        'Run commercial cleaning sales in Relaticle: walkthroughs, quotes and trial cleans as board columns, with floor area and contract end dates on each company.'
    );

    $setup = [
        [
            __('Stages that match how you sell'),
            __('Rename, add or remove stages until the pipeline reads Walkthrough, Quote, Trial clean, Contract and Active. The board columns follow the new names.'),
        ],
        [
            __('The numbers you price on'),
            __('Add custom attributes to companies, such as floor area, visits per week and the date a contract ends. They sit on each company record, and the company list filters by them.'),
        ],
        [
            __('Renewals you can see coming'),
            __('Filter companies by the contract end date to find the contracts that end next quarter. Then add a task to call each site before its contract ends.'),
        ],
    ];

    $faqs = [
        [
            __('Can Relaticle track recurring cleaning contracts?'),
            __('Yes, as a monthly amount and a renewal date. Put the monthly value in the opportunity amount and add a date attribute such as Contract ends to the company. Filter the company list by that date to see which contracts come up for renewal.'),
        ],
        [
            __('Do I pay per user?'),
            __('No. Every Relaticle Cloud plan includes unlimited users, so supervisors and sales staff can each have an account. The open-source version is free to self-host.'),
        ],
        [
            __('Can I import my current client list?'),
            __('Yes. Import companies, people and opportunities from a CSV file, including a column for each custom attribute.'),
        ],
    ];

    $friday = today()->next('Friday')->isoFormat('ll');
    $quote = __('Quote');
    $quoted = [['Meridian House', ':company office cleaning', 4800], ['Kestrel Fitness', ':company daily cleaning', 2900], ['Oakline Clinic', ':company clinical cleaning', 1600]];
    $planSteps = [
        [
            'entity' => 'opportunity',
            'title' => \Relaticle\Chat\Enums\ProposalEntity::Opportunity->batchSummary(\Relaticle\Chat\Enums\PendingActionOperation::Update, count($quoted)),
            'shared' => __(':field to :value', ['field' => __('Stage'), 'value' => $quote]),
            'items' => array_map(fn (array $row): array => ['name' => __($row[1], ['company' => $row[0]]), 'fields' => [
                [__('Stage'), $quote, 'badge'],
                [__('Amount'), \Illuminate\Support\Number::currency($row[2], 'USD')],
            ]], $quoted),
        ],
        [
            'entity' => 'task',
            'title' => \Relaticle\Chat\Enums\ProposalEntity::Task->batchSummary(\Relaticle\Chat\Enums\PendingActionOperation::Create, count($quoted)),
            'items' => array_map(fn (array $row): array => ['name' => __('Follow-up call with :company', ['company' => $row[0]]), 'fields' => [
                [__('Linked companies'), $row[0]],
                [__('Due Date'), $friday],
            ]], $quoted),
        ],
    ];

    $boardColumns = [
        ['label' => __('Walkthrough'), 'color' => \App\Enums\OptionColor::Purple->value, 'cards' => [
            ['title' => __(':company cleaning', ['company' => 'Fairmead Learning Centre']), 'company' => 'Fairmead Learning Centre', 'amount' => null, 'date' => today()->addDays(18)],
            ['title' => __(':company cleaning', ['company' => 'Dunmore Coworking']), 'company' => 'Dunmore Coworking', 'amount' => null, 'date' => today()->addDays(26)],
        ]],
        ['label' => $quote, 'color' => \App\Enums\OptionColor::Blue->value, 'cards' => array_map(fn (array $row): array => [
            'title' => __($row[1], ['company' => $row[0]]), 'company' => $row[0], 'amount' => $row[2], 'date' => today()->addDays(9),
        ], $quoted)],
        ['label' => __('Trial clean'), 'color' => \App\Enums\OptionColor::Cyan->value, 'collapsed' => true, 'cards' => []],
        ['label' => __('Contract'), 'color' => \App\Enums\OptionColor::Amber->value, 'collapsed' => true, 'cards' => []],
        ['label' => __('Active'), 'color' => \App\Enums\OptionColor::Green->value, 'cards' => [
            ['title' => __(':company office cleaning', ['company' => 'Calder Works']), 'company' => 'Calder Works', 'amount' => 5600, 'date' => today()->subDays(40)],
            ['title' => __(':company daily cleaning', ['company' => 'Tannery Lofts']), 'company' => 'Tannery Lofts', 'amount' => 3100, 'date' => today()->subDays(12)],
        ]],
    ];

    $contractsEnding = array_map(fn (array $row): array => [$row[0], \Illuminate\Support\Number::format($row[1]), (string) $row[2], today()->addDays($row[3])->isoFormat('ll')], [
        ['Calder Works', 18000, 5, 24],
        ['Tannery Lofts', 9500, 7, 47],
        ['Norvale Gym', 22400, 3, 66],
        ['Sable & Finch', 6200, 2, 83],
    ]);

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
>
    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('CRM for cleaning businesses') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('A cleaning CRM') }}
            <span class="text-gray-500 dark:text-gray-400">{{ __('from walkthrough to contract.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('Follow every site from the first walkthrough to an active contract, with its floor area and weekly visits on the company record.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ LocalizedUrl::route('pricing') }}">
                    {{ __('See pricing') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __(':days-day trial, no card, unlimited users.', ['days' => $trialDays]) }}</p>
        </x-slot:aside>

        <x-slot:visual>
            <x-cloud::marketing.stage light="center" class="bg-tint rounded-2xl px-3 py-8 sm:px-4 sm:py-12">
                <x-cloud::marketing.app-screen
                    class="w-full"
                    icon="lucide-circle-dollar-sign"
                    layout="board"
                    :title="__('filament/resources/opportunity.navigation_label')"
                    :view="__('filament/pages/boards.view_switcher.opportunity_board')"
                    :new-label="__('filament-actions::create.single.label', ['label' => __('filament/resources/opportunity.label')])"
                >
                    <x-cloud::marketing.app-board :columns="$boardColumns"/>
                </x-cloud::marketing.app-screen>
                <p class="mt-3 text-right text-xs text-gray-500 dark:text-gray-400">{{ __('Example workspace: Clearwell Cleaning') }}</p>
            </x-cloud::marketing.stage>
        </x-slot:visual>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="setup">
        <x-cloud::marketing.frame-header :label="__('Set it up once')" :lead="__('Your stages, your numbers.')">
            {{ __('No code and no consultant.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3 lg:col-span-4 lg:grid-cols-1">
                @foreach($setup as $index => [$setupTitle, $setupText])
                    <div class="bg-surface p-6 md:p-8">
                        <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $setupTitle }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $setupText }}</p>
                    </div>
                @endforeach
            </div>

            <div class="bg-tint lg:col-span-8">
                <div class="bg-dots flex h-full items-center justify-center p-4 sm:p-8 md:p-12">
                    <x-cloud::marketing.app-screen
                        class="w-full max-w-2xl"
                        icon="lucide-building"
                        :title="__('filament/resources/company.navigation_label')"
                        :view="__('filament/panel.tables.views.standard', ['records' => __('filament/resources/company.plural_label')])"
                        :new-label="__('filament-actions::create.single.label', ['label' => __('filament/resources/company.label')])"
                        :filters="[[
                            'field' => __('Contract ends'),
                            'icon' => 'lucide-calendar',
                            'condition' => __('filament/panel.tables.filters.conditions.in_the_next'),
                            'value' => trans_choice('filament/panel.tables.filters.periods.months', 3, ['count' => 3]),
                        ]]"
                    >
                        <x-cloud::marketing.app-list
                            :columns="[
                                ['label' => __('filament/resources/company.fields.name.label')],
                                ['label' => __('Floor area (sq ft)'), 'icon' => 'lucide-hash'],
                                ['label' => __('Visits per week'), 'icon' => 'lucide-hash'],
                                ['label' => __('Contract ends'), 'icon' => 'lucide-calendar'],
                            ]"
                            :rows="$contractsEnding"
                        />
                    </x-cloud::marketing.app-screen>
                </div>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="after-the-quotes">
        <x-cloud::marketing.frame-header :label="__('After a round of quotes')" :lead="__('One message updates the board.')">
            {{ __('You approve it first.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-tint lg:col-span-7">
                <x-cloud::marketing.chat-plan
                    width="max-w-xl"
                    :message="__('Walkthroughs done. Quotes went out: Meridian House $4,800 a month, Kestrel Fitness $2,900, Oakline Clinic $1,600. Move all three to Quote, set the amounts, and add a follow-up call for each on Friday.')"
                    :summary="__('Six changes. Three opportunities move to Quote with their amounts, and each company gets a follow-up call on Friday.')"
                    :steps="$planSteps"
                />
            </div>

            <div class="bg-surface flex flex-col justify-center p-6 md:p-10 lg:col-span-5">
                <x-cloud::marketing.eyebrow class="mb-4">{{ __('Built into Relaticle') }}</x-cloud::marketing.eyebrow>
                <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Tell :name what happened today', ['name' => $assistantName]) }}</h3>
                <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __(':name, the built-in AI assistant, puts the stage moves, the amounts and the follow-up calls on one card. Nothing is written until you approve it.', ['name' => $assistantName]) }}</p>
                <p class="type-prose mt-4 text-gray-500 dark:text-gray-400">{{ __('Then ask what the pipeline is worth. In this example, :name adds up the updated records: $9,300 a month, all in Quote.', ['name' => $assistantName]) }}</p>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="cleaning-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-5">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Questions from cleaning businesses, answered') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ LocalizedUrl::route('contact') }}" class="{{ $linkClass }}">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-7 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="cleaning-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Set up your cleaning pipeline today.')" :continuation="__('Free to start, self-host whenever you want.')">
        <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Start for free') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ LocalizedUrl::route('pricing') }}">
            {{ __('See pricing') }}
        </x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

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
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(LocalizedUrl::route('home')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('CRM for cleaning businesses'))->item(LocalizedUrl::route('crmForCleaningBusiness')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
