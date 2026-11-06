@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    // offered(), not available(): this page describes what a plan includes, so it must
    // not change because this install is missing a provider key. It still drops models
    // a probe measured as unable to call tools, which the assistant could never run.
    $toolCapableCloudModels = collect(resolve(\Relaticle\Chat\Services\ModelRegistry::class)->offered())
        ->map(fn (\Relaticle\Chat\Support\ModelDescriptor $model): array => [
            'label' => $model->displayLabel(),
            'provider' => $model->provider,
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
    $maxPlanSteps = (int) config('chat.max_plan_steps');
    $undoSeconds = (int) config('email-integration.outbox.undo_send_window_seconds');
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

    $controls = [
        [
            __(':name never writes without your approval', ['name' => $assistantName]),
            __('Every proposed create, update, or delete docks as a card showing the old value and the new one. You approve or discard it; nothing saves until you do. An unanswered proposal expires after :duration.', ['duration' => $pendingActionExpiry]),
            'approval',
        ],
        [
            __('Leave out any record before you approve'),
            __('A batch of up to :max records lists every record on one card. Leave out the ones you do not want, approve the rest, and nothing you left out is written.', ['max' => $maxBatchSize]),
            'batch',
        ],
        [
            __('Answers you can check'),
            __(':name links every record it names, so you can open the source. Like any AI model, it can misread a record, so check an answer that matters.', ['name' => $assistantName]),
            'links',
        ],
    ];

    $limits = [
        __('Deleting a record never removes the records linked to it. Ask for each one by name.'),
        __('It cannot export records to a file. It links you to the export page instead.'),
        __('It searches the web only after you allow it.'),
    ];

    $faqs = [
        [
            __('What can :name change in my CRM?', ['name' => $assistantName]),
            __(
                'Companies, people, opportunities, tasks, and notes, one record or a batch of up to :max, plus custom fields and member invitations.:emails Every write needs your approval first.',
                ['max' => $maxBatchSize, 'emails' => $emailIntegrationActive ? ' '.__('It also proposes emails, and each one waits for its own Send.') : '']
            ),
        ],
        [
            __('Can :name import a file?', ['name' => $assistantName]),
            __(
                'Yes. Attach a CSV, TXT or MD file of up to :size MB to your message. :name proposes the records in it, up to :max at a time. For a bigger CSV of people or companies, it links the import wizard with your columns already mapped.',
                ['name' => $assistantName, 'size' => intdiv(\Relaticle\Chat\Actions\StoreChatAttachment::MAX_KILOBYTES, 1024), 'max' => $maxBatchSize]
            ),
        ],
        [
            __('Does :name invent data?', ['name' => $assistantName]),
            __(
                ':name answers from the records in your workspace, Relaticle\'s product documentation and, when you allow it, the web. It links the records it names, so you can open the source. Like any AI model, it can misread a record. Check an answer that matters, and read each proposal before you approve it.',
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
>
    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __(':name, the built-in AI assistant', ['name' => $assistantName]) }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __(':name drafts every change.', ['name' => $assistantName]) }}
            <span class="text-gray-500 min-[360px]:whitespace-nowrap dark:text-gray-400">{{ __('You make the call.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('It opens beside every page in Relaticle and answers from your records. Nothing it proposes is saved until you approve it.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#demo" class="lg:hidden">
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

        <div class="bg-rail grid gap-px">
            <x-cloud::marketing.showcase
                data-feature-row
                :label="__('01 · Plans')"
                :lead="__('One ask, several changes.')"
                :facts="[
                    __('Up to :steps linked steps in one plan', ['steps' => $maxPlanSteps]),
                    __('Companies, people, opportunities, tasks and notes'),
                    __('Every active custom field, with options shown as text'),
                ]"
                :link-label="__('How approvals work')"
                :link-href="$documentationActive ? route('help.show', ['category' => 'ai-assistant', 'slug' => 'approve-what-the-assistant-changes']) : null"
            >
                {{ __('Linked in order and approved once.') }}

                <x-slot:visual>
                    @include('cloud::marketing.partials.ai-plan-mock')
                </x-slot:visual>
            </x-cloud::marketing.showcase>

            <x-cloud::marketing.showcase
                data-feature-row
                :label="__('02 · Answers')"
                :lead="__('Product questions, answered from the docs.')"
                :facts="[
                    __('Searches Relaticle\'s own documentation'),
                    __('Links the section each answer came from'),
                    __('Points you to the page for what chat cannot do'),
                ]"
                :link-label="__('What :name can answer', ['name' => $assistantName])"
                :link-href="$documentationActive ? route('help.show', ['category' => 'ai-assistant', 'slug' => 'ask-questions-about-your-data']) : null"
            >
                {{ __('With the source one click away.') }}

                <x-slot:visual>
                    @include('cloud::marketing.partials.ai-docs-mock')
                </x-slot:visual>
            </x-cloud::marketing.showcase>

            @if($emailIntegrationActive)
                <x-cloud::marketing.showcase
                    data-feature-row
                    :label="__('03 · Email')"
                    :lead="__('It writes the email.')"
                    :facts="[
                        __('Shows the mailbox, recipients, message and signature'),
                        __('Approve all never sends an email'),
                        trans_choice('An Undo button stays up for :count second after Send|An Undo button stays up for :count seconds after Send', $undoSeconds, ['count' => $undoSeconds]),
                    ]"
                    :link-label="__('Use your email with an AI assistant')"
                    :link-href="$documentationActive ? route('help.show', ['category' => 'email-and-calendar', 'slug' => 'use-your-email-with-an-ai-assistant']) : null"
                >
                    {{ __('You press Send.') }}

                    <x-slot:visual>
                        @include('cloud::marketing.partials.ai-email-mock')
                    </x-slot:visual>
                </x-cloud::marketing.showcase>
            @endif
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

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3" data-feature-row>
            @foreach($controls as $index => [$controlTitle, $controlDescription, $controlMock])
                <div class="bg-surface flex flex-col">
                    <div class="feat-card bg-tint bg-dots flex min-h-60 items-center justify-center px-6 py-10 md:h-72" data-ai-control="{{ $controlMock }}">
                        @include('cloud::marketing.partials.ai-control-mock', ['mock' => $controlMock])
                    </div>
                    <div class="border-rail border-t p-6 md:p-10">
                        <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $controlTitle }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $controlDescription }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="data">
        <x-cloud::marketing.frame-header :label="__('Your data')" :lead="__('Clear about what it sees.')">
            {{ __('And which model answers.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid gap-px">
            <x-cloud::marketing.showcase
                data-feature-row
                :label="__('Models')"
                :lead="__('You choose the model.')"
                :facts="array_values(array_filter([
                    __('Answers with the permissions you already have'),
                    $modelChoiceLine,
                    __('Relaticle does not train AI models on your CRM data'),
                ]))"
                :link-label="__('Which providers handle a request')"
                :link-href="route('security').'#ai'"
            >
                {{ __('Right from the message box.') }}

                <x-slot:visual>
                    @include('cloud::marketing.partials.ai-model-mock', ['models' => $toolCapableCloudModels->all()])
                </x-slot:visual>
            </x-cloud::marketing.showcase>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Beyond the app')" :lead="__('Bring your own assistant.')">
            {{ __('Or your own server.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-2">
            <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                <div>
                    <div class="mb-6 flex flex-wrap gap-2" aria-hidden="true">
                        <x-cloud::marketing.actor as="claude"/>
                        <x-cloud::marketing.actor as="chatgpt"/>
                    </div>
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
                    <div class="mb-6 flex flex-wrap gap-2" aria-hidden="true">
                        @foreach([['ri-claude-fill', 'Claude'], ['ri-openai-fill', 'GPT'], ['ri-server-line', __('Ollama')]] as [$providerIcon, $providerLabel])
                            <span class="border-rail inline-flex items-center gap-1.5 rounded-full border py-0.5 pl-1 pr-2.5 text-xs font-medium text-gray-700 dark:text-gray-300">
                                <span class="flex h-5 w-5 items-center justify-center"><x-dynamic-component :component="$providerIcon" class="h-3.5 w-3.5"/></span>
                                {{ $providerLabel }}
                            </span>
                        @endforeach
                    </div>
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

    <x-cloud::marketing.closing-band
        :title="__('Try :name in your own workspace.', ['name' => $assistantName])"
        :lead="__('Free to start, no card. Self-host it whenever you want.')"
    >
        <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Start for free') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('pricing') }}">
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
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name($assistantName)->item(route('ai')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
