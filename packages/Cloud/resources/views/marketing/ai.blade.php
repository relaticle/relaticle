@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);

    // offered(), not available(): this page describes what a plan includes, so it must
    // not change because this install is missing a provider key. It still drops models
    // a probe measured as unable to call tools, which the assistant could never run.
    $toolCapableCloudModels = collect(resolve(\Relaticle\Chat\Services\ModelRegistry::class)->offered())
        ->map(fn (\Relaticle\Chat\Support\ModelDescriptor $model): array => [
            'label' => $model->displayLabel(),
            'min_plan' => $model->minPlan->value,
        ]);
    $freeCloudModels = $toolCapableCloudModels->where('min_plan', 'free')->pluck('label')->join(', ', ' and ');
    $paidCloudModels = $toolCapableCloudModels->where('min_plan', 'pro')->pluck('label')->join(', ', ' and ');

    // Independent sentences rather than one with two holes in it: the catalog is editable at
    // runtime, and a tier with no model rendered " is free on every plan".
    $selfHostedNote = __('A self-hosted install supplies its own provider key, so you choose the model and pay the provider directly, or run a local one.');

    $proCredits = number_format(\App\Enums\Plan::Pro->credits());
    $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;
    $maxBatchSize = (int) config('chat.max_batch_size');
    // Rendered as a human duration so raising the config never leaks "1440 minutes" into the page.
    $pendingActionExpiry = \Carbon\CarbonInterval::minutes((int) config('chat.pending_action_expiry_minutes'))
        ->cascade()
        ->forHumans(short: false, parts: 1);

    if ($billingActive) {
        $whichModelsAnswer = trim(implode(' ', array_filter([
            $freeCloudModels === ''
                ? __('Every plan can use any self-hosted model you connect yourself.')
                : __(':freeModels on every plan.', ['freeModels' => $freeCloudModels]),
            $paidCloudModels === ''
                ? ''
                : __('A paid plan additionally unlocks :paidModels, which cost more credits per reply.', ['paidModels' => $paidCloudModels]),
            $selfHostedNote,
        ])));
        $modelChoiceLine = trim(implode(' ', array_filter([
            $freeCloudModels === ''
                ? __('Any self-hosted model you connect is free on every plan.')
                : __(':freeModels is free on every plan.', ['freeModels' => $freeCloudModels]),
            $paidCloudModels === ''
                ? ''
                : __('Switch to :paidModels on a paid plan when a harder question calls for it.', ['paidModels' => $paidCloudModels]),
        ])));
        $includedPlanQuestion = __('Is :name included with Relaticle Cloud?', ['name' => $assistantName]);
        $includedPlanAnswer = __(
            'Yes. New Cloud workspaces include :name during a :days-day Cloud Pro trial. Cloud Pro includes a :credits-credit monthly allowance and every supported cloud model. If the trial ends without a subscription, hosted access pauses. Self-hosted installs do not meter credits and require your own provider key or local model.',
            ['name' => $assistantName, 'days' => $trialDays, 'credits' => $proCredits]
        );
    } else {
        $whichModelsAnswer = __('Every model this installation offers is open to every workspace. There is no plan gate.').' '.$selfHostedNote;
        $modelChoiceLine = __('Every model this installation offers is open to every workspace.');
        $includedPlanQuestion = __('Is :name included in the free plan?', ['name' => $assistantName]);
        $includedPlanAnswer = __(
            'Yes. :name does not meter AI credits on this installation, so there is no credit limit. A self-hosted install ships without a provider. You add your own key or local model, and pay that provider directly.',
            ['name' => $assistantName]
        );
    }

    $title = __(':name: AI Assistant for Relaticle CRM', ['name' => $assistantName]).' - Relaticle';
    $description = __(
        ":name is Relaticle's built-in AI assistant. It finds, creates, and updates your records, and proposes every change for you to approve or reject first.",
        ['name' => $assistantName]
    );

    $capabilities = [
        [__('Creates and updates any record'), __('Companies, people, opportunities, tasks, and notes. Ask for a field to change and it proposes the edit.')],
        [__('Proposes many records at once'), __('Ask for a batch of up to :max records. You review them one at a time and keep the ones you want.', ['max' => $maxBatchSize])],
        [__('Knows your custom fields'), __('Every active custom field your team has defined works out of the box, with option labels shown as text, not raw IDs.')],
        [__('Answers questions from the docs'), __('Ask how something works and it searches Relaticle\'s own documentation for the answer, with a link to the source.')],
        [__('Finds and summarizes your data'), __('Look up a record, list your team, or get a rollup of your pipeline without opening a single page.')],
        [__('Guides you to the right page'), __('For the few things it cannot do itself, like importing a file or changing a field type, it links you straight to where you can.')],
    ];

    $controls = [
        [
            __(':name never writes without your approval', ['name' => $assistantName]),
            __('Every proposed create, update, or delete renders as a card showing the old value and the new one. You approve or reject it; nothing saves until you do. An unanswered proposal expires after :duration.', ['duration' => $pendingActionExpiry]),
        ],
        [
            __('Batches are reviewed record by record'),
            __('A batch of up to :max records is stepped through one at a time. Keep the ones you want, reject the rest, and nothing you skipped is written.', ['max' => $maxBatchSize]),
        ],
        [
            __('Answers you can check'),
            __(':name links every record it names, so you can open the source. Like any AI model, it can misread a record, so check an answer that matters.', ['name' => $assistantName]),
        ],
    ];

    $limits = [
        __('It proposes each email on a card. Nothing is sent until you click Send.'),
        __('It cannot import a file or change a field type. It links you to the page that can.'),
        __('It reads only your workspace and Relaticle\'s documentation, not the web.'),
    ];

    $faqs = [
        [
            __('What can :name change in my CRM?', ['name' => $assistantName]),
            __(
                'Companies, people, opportunities, tasks, and notes, one record or a batch of up to :max, plus custom fields and member invitations. It drafts emails for you to send and never sends mail itself. Every write needs your approval first.',
                ['max' => $maxBatchSize]
            ),
        ],
        [
            __('Does :name invent data?', ['name' => $assistantName]),
            __(
                ':name answers from the records in your workspace and Relaticle\'s product documentation. It links the records it names, so you can open the source. Like any AI model, it can misread a record. Check an answer that matters, and read each proposal before you approve it.',
                ['name' => $assistantName]
            ),
        ],
        [
            __('Which models power :name?', ['name' => $assistantName]),
            $whichModelsAnswer,
        ],
        [
            $includedPlanQuestion,
            $includedPlanAnswer,
        ],
        [
            __('Does Relaticle train AI models on my data?'),
            __('No. Relaticle does not train AI models on your CRM data, does not sell it, and does not use it for advertising.'),
        ],
    ];

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
    :ogImage="url('/images/open-graph-ai.jpg').'?v=1'"
    framed
>
    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Meet :name', ['name' => $assistantName]) }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('The AI assistant inside your CRM.') }}
            <span class="text-gray-500 dark:text-gray-400">{{ __('It asks before it writes.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __(':name, the built-in AI assistant, finds, creates and updates your records. You approve every change before it happens.', ['name' => $assistantName]) }}
            </p>

            <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#demo">
                    {{ __('See it work') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-5 text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('aiNativeCrm') }}" class="{{ $linkClass }}">{{ __('What makes a CRM AI-native?') }}</a>
            </p>
        </x-slot:aside>

        @include('cloud::marketing.partials.ai-playground')
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Capabilities')" :lead="__('What :name actually does.', ['name' => $assistantName])">
            {{ __('Every capability runs against your real workspace.') }}
        </x-cloud::marketing.frame-header>

        <div class="grid grid-cols-1 gap-x-10 gap-y-10 px-6 py-12 sm:grid-cols-2 md:px-10 md:py-16 lg:grid-cols-3">
            @foreach($capabilities as [$capabilityTitle, $capabilityDescription])
                <div class="border-rail border-t pt-5">
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $capabilityTitle }}</h3>
                    <p class="type-prose mt-1.5 text-gray-500 dark:text-gray-400">{{ $capabilityDescription }}</p>
                </div>
            @endforeach
        </div>

        <div class="border-rail bg-rail grid grid-cols-1 gap-px border-t lg:grid-cols-12">
            <div class="bg-surface px-6 py-8 md:px-10 lg:col-span-4">
                <h3 class="type-label text-gray-500 dark:text-gray-400">{{ __('What it can’t do') }}</h3>
            </div>
            <div class="bg-surface px-6 md:px-10 lg:col-span-8">
                <ul class="divide-rail divide-y">
                    @foreach($limits as $limit)
                        <li class="type-prose py-4 text-gray-700 dark:text-gray-300">{{ $limit }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Control')" :lead="__('You stay in control.')">
            {{ __('It earns trust by asking first.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($controls as $index => [$controlTitle, $controlDescription])
                <div class="bg-surface p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $controlTitle }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $controlDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="data">
        <x-cloud::marketing.frame-header :label="__('Your data')" :lead="__('Clear about what it sees.')">
            {{ __('And which model answers.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            <div class="bg-surface p-6 md:p-10">
                <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('What it reads') }}</h3>
                <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __(':name answers from the records in your workspace and Relaticle\'s product documentation, with the permissions you already have.', ['name' => $assistantName]) }}</p>
            </div>
            <div class="bg-surface p-6 md:p-10">
                <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Transparent credits, your choice of model') }}</h3>
                <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $modelChoiceLine }}</p>
                @if($billingActive)
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        <a href="{{ route('pricing') }}#pricing-faq" class="{{ $linkClass }}">{{ __('How credits work') }}</a>
                    </p>
                @endif
            </div>
            <div class="bg-surface p-6 md:p-10">
                <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('No training on your data') }}</h3>
                <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __('Relaticle does not train AI models on your CRM data.') }}</p>
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    <a href="{{ route('security') }}#ai" class="{{ $linkClass }}">{{ __('Which providers handle a request') }}</a>
                </p>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Beyond the app')" :lead="__('Bring your own assistant.')">
            {{ __('Or your own server.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-2">
            <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                <div>
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('MCP server') }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Use Relaticle from Claude or ChatGPT') }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __('Relaticle ships a built-in MCP server, so your own AI client can read and write your CRM directly. It works with Claude, ChatGPT, and any other MCP-compatible client. MCP calls run against the same records, and because you authorized the client yourself, they apply straight away rather than waiting for an in-app approval.') }}</p>
                </div>
                <div class="mt-auto flex flex-wrap gap-3">
                    @foreach(\Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::cases() as $assistant)
                        <x-cloud::marketing.button variant="secondary" size="sm" :href="route($assistant->routeName())" iconTrailing="ri-arrow-right-line">
                            {{ __('Relaticle for :assistant', ['assistant' => $assistant->label()]) }}
                        </x-cloud::marketing.button>
                    @endforeach
                    @feature(App\Features\Documentation::class)
                        <x-cloud::marketing.button variant="secondary" size="sm" href="{{ route('documentation.index') }}">
                            {{ __('Read the developer docs') }}
                        </x-cloud::marketing.button>
                    @endfeature
                </div>
            </div>

            <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                <div>
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('Self-hosted AI') }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Run the assistant on your own server') }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __('Self-hosting Relaticle does not mean giving up the assistant. On your own server, bring your own API key for Claude or GPT, or point it at a local model with Ollama and keep every request on your own infrastructure.') }}</p>
                </div>
                <div class="mt-auto">
                    <x-cloud::marketing.button variant="secondary" size="sm" href="{{ route('selfHosted') }}" iconTrailing="ri-arrow-right-line">
                        {{ __('See self-hosting options') }}
                    </x-cloud::marketing.button>
                </div>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="ai-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Questions about :name, answered', ['name' => $assistantName]) }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('contact') }}" class="{{ $linkClass }}">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="ai-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <div class="bg-warm relative overflow-hidden px-6 py-24 text-center md:py-32">
            <div class="mask-top-corners absolute inset-0 text-gray-400 dark:text-gray-600" aria-hidden="true">
                <svg class="absolute inset-0 size-full">
                    <pattern id="ai-cta-dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.2" fill="currentColor"/></pattern>
                    <rect width="100%" height="100%" fill="url(#ai-cta-dots)"/>
                </svg>
            </div>

            <div class="relative mx-auto max-w-2xl">
                <h2 class="type-title text-gray-950 md:text-5xl dark:text-white">{{ __('Try :name in your own workspace', ['name' => $assistantName]) }}</h2>
                <p class="type-prose mx-auto mt-5 text-gray-500 dark:text-gray-400">{{ __('Free to start, no credit card required. Self-host it yourself whenever you want.') }}</p>

                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
                        {{ __('Start for free') }}
                    </x-cloud::marketing.button>
                    <x-cloud::marketing.button variant="secondary" href="{{ route('pricing') }}">
                        {{ __('See pricing') }}
                    </x-cloud::marketing.button>
                </div>
            </div>
        </div>
    </x-cloud::marketing.frame>

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
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name($assistantName)->item(route('ai')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
