@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $docsActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
    $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;
    $pageUrl = route($assistant->routeName());
    $name = $assistant->label();
    $sibling = $assistant->sibling();
    $isChatGpt = $assistant === \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT;

    $steps = $isChatGpt
        ? [
            [__('Install the plugin'), __('Open the Relaticle plugin in the ChatGPT plugin directory and click Install plugin.')],
            [__('Approve access'), __('ChatGPT sends you to Relaticle to sign in. Approve access and pick the workspace it may use.')],
            [__('Ask'), __('Start a message with @Relaticle, then ask about your pipeline or tell it what to add.')],
        ]
        : [
            [__('Add the connector'), __('In Claude, open Connectors and add a custom connector with this URL.')],
            [__('Approve access'), __('Claude sends you to Relaticle to sign in. Approve access and pick the workspace it may use.')],
            [__('Ask'), __('Back in Claude, ask about your pipeline or tell it what to add.')],
        ];

    $selfHostedAnswer = $isChatGpt
        ? __('The plugin in the ChatGPT directory connects to Relaticle Cloud. A self-hosted install includes the same MCP server on your own domain, and the MCP server guide covers how a client connects to it.')
        : __('A self-hosted install includes the same MCP server on your own domain. The MCP server guide covers how a client connects to it.');

    $title = __('CRM for :name: run your pipeline from chat', ['name' => $name]).' - Relaticle';
    $description = __(
        'Connect :name to Relaticle and work your target companies from the chat: search the pipeline, add people, log tasks and notes. For founder-led sales.',
        ['name' => $name]
    );

    $fit = [
        [__('A named list, not a lead funnel'), __('Import your target companies from a CSV, or have :name add them as you work. Each company keeps its people, opportunities, tasks and notes together.', ['name' => $name])],
        [__('Attributes that fit your deals'), __('Add custom attributes for the dates, criteria and stages your deals turn on. :name reads and sets them by name, option labels included.', ['name' => $name])],
        [__('One price for the whole team'), __('Unlimited users on one flat price. Nothing changes on the bill when a second person joins the deal.')],
    ];

    $caveats = [
        [__('Changes apply directly'), __('A connected assistant writes straight to your records, so rely on its own confirmation prompts. :assistant, the built-in assistant, asks before every change instead.', ['assistant' => $assistantName])],
        [__('It acts as you, in one workspace'), __('It can do what you can do there, and nothing else. Revoke it at any time under Settings, Access Tokens.')],
        [__('It spends no AI credits'), __('Credits are only for the built-in assistant. Relaticle does not charge for work done through :name.', ['name' => $name])],
        [__('It works across your records'), __('Search, create, update and delete across companies, people, opportunities, tasks and notes.')],
    ];

    $costAnswer = $billingActive
        ? __('The connector adds no charge and spends no AI credits. It needs an active Relaticle Cloud workspace. Every new workspace starts with a :days-day Cloud Pro trial. After the trial, the workspace needs a Cloud Pro subscription to stay active.', ['days' => $trialDays])
        : __('The connector adds no charge and spends no AI credits. It needs a Relaticle Cloud workspace or a self-hosted install.');

    $faqs = [
        [
            __('Does Relaticle work with :name?', ['name' => $name]),
            __('Yes. :name connects to the Relaticle MCP server with OAuth. It can search, create, update and delete companies, people, opportunities, tasks and notes in the workspace you pick, custom attributes included.', ['name' => $name]),
        ],
        [
            __('Can :name change my records without asking me?', ['name' => $name]),
            __('Through the :name connector, yes. Changes made through the connector apply directly inside the workspace you approved, with no approval card in Relaticle, so use the confirmation prompts :name gives you. The assistant built into Relaticle, :assistant, proposes every change for you to approve first.', ['name' => $name, 'assistant' => $assistantName]),
        ],
        [
            __('What does it cost?'),
            $costAnswer,
        ],
        [
            __('Can I use it with a self-hosted install?'),
            $selfHostedAnswer,
        ],
    ];

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
    :ogImage="url('/images/open-graph-ai.jpg').'?v=2'"
>
    <x-cloud::marketing.hero glow>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Relaticle for :name', ['name' => $name]) }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('The CRM you run from :name.', ['name' => $name]) }}
            <span class="text-gray-500 dark:text-gray-400">{{ __('Ask, add, log.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('For founders who sell to a named list of companies. Connect :name once, then work your pipeline from the chat you already have open.', ['name' => $name]) }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#connect">
                    {{ __('See how to connect') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('No code and no API keys. The setup is a consent screen.') }}</p>
        </x-slot:aside>

        <x-slot:visual>
            <x-cloud::marketing.stage light="seam" class="bg-tint rounded-2xl">
                @include('cloud::marketing.partials.assistant-playground')
            </x-cloud::marketing.stage>
        </x-slot:visual>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="connect">
        <x-cloud::marketing.frame-header :label="__('Connect')" :lead="__('Connect :name in three steps.', ['name' => $name])">
            {{ __('The whole setup is a consent screen.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($steps as $index => [$stepTitle, $stepBody])
                <div class="bg-surface flex flex-col gap-4 p-6 md:p-10">
                    <div>
                        <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $stepTitle }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $stepBody }}</p>
                    </div>

                    @if($index === 0 && ! $isChatGpt)
                        <x-cloud::marketing.copy-line :value="$mcpUrl" class="mt-auto"/>
                    @endif

                    @if($index === 0 && $isChatGpt)
                        <div class="mt-auto">
                            <x-cloud::marketing.button variant="secondary" size="sm" href="{{ $chatGptPluginUrl }}" :external="true" iconTrailing="lucide-arrow-up-right">
                                {{ __('Open the ChatGPT plugin') }}
                            </x-cloud::marketing.button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="bg-surface border-rail flex flex-col gap-3 border-t px-6 py-5 sm:flex-row sm:items-center md:px-8">
            <p class="type-prose text-gray-700 dark:text-gray-300">
                {{ __('Using :sibling instead?', ['sibling' => $sibling->label()]) }}
                <a href="{{ route($sibling->routeName()) }}" class="{{ $linkClass }}">{{ __('Relaticle for :sibling', ['sibling' => $sibling->label()]) }}</a>
            </p>
            @if($docsActive)
                <x-cloud::marketing.button variant="secondary" size="sm" href="{{ route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt']) }}" class="sm:ml-auto">
                    {{ __('Read the setup guide') }}
                </x-cloud::marketing.button>
            @endif
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="fit">
        <x-cloud::marketing.frame-header :label="__('Who it is for')" :lead="__('Built for founder-led sales.')">
            {{ __('You know every company on your list and run the deals yourself.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($fit as [$fitTitle, $fitDescription])
                <div class="bg-surface p-6 md:p-10">
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $fitTitle }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $fitDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="know">
        <x-cloud::marketing.frame-header :label="__('Before you connect')" :lead="__('What to know.')">
            {{ __('Four facts the setup screen cannot tell you.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-2">
            @foreach($caveats as [$caveatTitle, $caveatDescription])
                <div class="bg-surface p-6 md:p-10">
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $caveatTitle }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $caveatDescription }}</p>
                </div>
            @endforeach
        </div>

        <div class="bg-surface border-rail border-t px-6 py-5 md:px-8">
            <p class="type-prose text-gray-700 dark:text-gray-300">
                {{ __('Prefer an assistant that asks first?') }}
                <a href="{{ route('ai') }}" class="{{ $linkClass }}">{{ __('Meet :name, the built-in AI assistant', ['name' => $assistantName]) }}</a>
            </p>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="assistant-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Questions about Relaticle and :name', ['name' => $name]) }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('contact') }}" class="{{ $linkClass }}">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="assistant-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Put your company list where :name can reach it.', ['name' => $name])" :continuation="__('Free to start, self-host whenever you want.')">
        <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Start for free') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('pricing') }}">
            {{ __('See pricing') }}
        </x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($webPage) => $webPage
                ->name($title)
                ->description($description)
                ->url($pageUrl))
            ->fAQPage(fn ($faqPage) => $faqPage
                ->mainEntity(collect($faqs)->map(fn (array $faq) => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Relaticle for :name', ['name' => $name]))->item($pageUrl),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
