@use(Relaticle\Cloud\Marketing\Support\LocalizedUrl)
@php
    $assistantName = (string) config('chat.assistant_name');
    $proposalExpiry = \Carbon\CarbonInterval::minutes((int) config('chat.pending_action_expiry_minutes'))->cascade()->forHumans();
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);
    $providerNames = ['anthropic' => 'Anthropic', 'openai' => 'OpenAI', 'gemini' => 'Google'];
    $offeredProviders = collect(resolve(\Relaticle\Chat\Services\ModelRegistry::class)->offered())
        ->map(fn (\Relaticle\Chat\Support\ModelDescriptor $model): string => $providerNames[$model->provider] ?? (string) $model->provider)
        ->unique()
        ->join(', ', ' '.__('or').' ');

    $faqs = array_values(array_filter([
        [__('Can :name create, change, or delete my CRM data without my approval?', ['name' => $assistantName]), __('No. :name proposes every create, update, and delete as a card showing exactly what will change. Nothing is written until you confirm it, and you can discard any proposal. An unanswered proposal expires after :expiry. Reading and searching need no approval.', ['name' => $assistantName, 'expiry' => $proposalExpiry])],
        [__('Do external AI agents need my approval for each change?'), __('No. An agent you connect over MCP, such as Claude or ChatGPT, acts with your permissions in the one workspace you approved, and its changes apply directly. Only :name adds a review step before every write.', ['name' => $assistantName])],
        [__('Does :name send my data to OpenAI or Anthropic?', ['name' => $assistantName]), __('On Relaticle Cloud, :name runs on the :providers model you pick in the message box. Self-hosted installs use their own provider keys or a local model through Ollama, so the destination is yours to choose. Relaticle keeps your conversation history in its own database, and the provider you pick processes each message under its own terms. Relaticle never trains on your data.', ['name' => $assistantName, 'providers' => $offeredProviders])],
        [
            __('What does Relaticle cost, and what happens when the trial ends?'),
            __('Cloud Pro is $:price a month per workspace billed yearly ($:monthly billed monthly), with unlimited users and records. Every new workspace starts a :days-day Cloud Pro trial with no card. Hosted access pauses when the trial ends without a subscription; your data stays and resumes when you subscribe.', [
                'price' => \App\Enums\Plan::Pro->yearlyPricePerMonth(),
                'monthly' => \App\Enums\Plan::Pro->monthlyPrice(),
                'days' => \App\Models\Workspace::PRO_TRIAL_DAYS,
            ]),
        ],
        [__('How do I deploy Relaticle?'), __('The quickest way is the hosted version at app.relaticle.com: sign up and start. To run it yourself, deploy with Docker Compose on your own server. Your CRM database stays there, and AI requests go only to the provider you configure.')],
        [__('Can I customize the data model?'), __('Yes. Add custom attributes to any record: text, email, phone, currency, date, select, multi-select, and links to other records. You can encrypt sensitive attributes. No code changes needed.')],
        [__('Is Relaticle production-ready?'), __('Yes. Relaticle Cloud runs in production today. Every change passes an automated test suite before it ships. Each workspace\'s data stays separate behind role-based permissions.')],
        $emailIntegrationActive
            ? [__('Does Relaticle sync my email and calendar?'), __('Yes. Connect a Google or Microsoft account and your email and meetings appear on the people, companies, and opportunities they involve. You can reply and send from the record, and you choose how much of each email your workspace can see.')]
            : null,
        [
            __('What does the AGPL-3.0 ask of me?'),
            __('Nothing, if you use Relaticle as it ships, hosted or self-hosted. The licence only asks that a modified version served to others over a network offers its users the modified source. This is a plain summary, not legal advice; the licence text governs.'),
        ],
        [__('What AI agents can I connect from outside?'), __('Claude, ChatGPT, Gemini, open-source models, or your own agent. They connect over MCP (Model Context Protocol), an open standard, and can read, create, update, delete, and analyze your CRM data.')],
        [__('What is MCP?'), __('MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server lets external agents list companies, create people, update opportunities, analyze pipelines, and more.')],
        [__('What can :name do?', ['name' => $assistantName]), __(':name is the AI assistant built into Relaticle. Ask it anything about your CRM and it works on your data: list and search records, draft follow-ups, summarize an opportunity, create a task, update or delete a record. @-mention any record (people, companies, opportunities, tasks, notes) to scope a question. Voice input and persistent, searchable history are included.', ['name' => $assistantName])],
        [__('How is Relaticle different from HubSpot or Salesforce?'), __('Relaticle is open source (AGPL-3.0), can be self-hosted so you own your data, ships with :name, a built-in AI assistant, lets Claude, ChatGPT, or your own agent work in the same records over MCP, and has no per-seat pricing. It\'s designed for people who want AI built in and AI integration without vendor lock-in.', ['name' => $assistantName])],
    ]));
@endphp

<x-cloud::guest-layout
    :title="config('app.name') . ' - ' . __('Open-Source AI CRM for Claude and ChatGPT')"
    :description="__('Open-source AI CRM for people who sell without a sales team. Work from Claude or ChatGPT, or ask :name, the built-in assistant. Free to self-host.', ['name' => $assistantName])"
    :ogTitle="config('app.name') . ' - ' . __('Open-Source AI CRM for Claude and ChatGPT')"
    :ogDescription="__('The open-source AI CRM for selling without a sales team. Work from Claude or ChatGPT, or ask :name, the built-in assistant.', ['name' => $assistantName])"
>
    @push('header')
        @vite('packages/Cloud/resources/js/motion.js')
    @endpush

    @include('cloud::marketing.home.partials.hero')
    @include('cloud::marketing.home.partials.works-with')
    @include('cloud::marketing.home.partials.features')
    @include('cloud::marketing.home.partials.story')
    @if($emailIntegrationActive)
        @include('cloud::marketing.home.partials.email')
    @endif
    @include('cloud::marketing.home.partials.capabilities')
    @include('cloud::marketing.home.partials.community')
    @include('cloud::marketing.home.partials.customer-voices')
    @include('cloud::marketing.home.partials.faq')
    <x-cloud::marketing.closing-band :title="__('Your CRM, your rules.')" :continuation="__('Start free, self-host whenever you like.')"/>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->softwareApplication(fn ($app) => $app
                ->name('Relaticle')
                ->applicationCategory('BusinessApplication')
                ->applicationSubCategory('CRM')
                ->operatingSystem('Linux, macOS, Windows')
                ->description(__('The open-source AI CRM for selling without a sales team. Self-hosted with :name, a built-in AI assistant (with @-mentions, approval before every write, voice, and persistent history), plus an MCP server, REST API, and custom attributes. Connect any external agent: Claude, ChatGPT, Gemini, or open-source models.', ['name' => $assistantName]))
                ->url(LocalizedUrl::route('home'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->name(__('Self-hosted'))->description(__('Free to self-host under the AGPL-3.0 license.'))->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', array_values(array_filter([
                    __(':name, a built-in AI assistant with @-mentions to records, approval before every write, and voice input', ['name' => $assistantName]),
                    __('Persistent searchable conversation history'),
                    __('MCP server for external AI agents'),
                    __('REST API with full CRUD operations'),
                    $emailIntegrationActive ? __('Gmail and Outlook email and calendar sync with per-email sharing controls') : null,
                    __('Custom attributes with per-attribute encryption'),
                    __('Self-hosted with full data ownership'),
                    __('Multi-workspace isolation with role-based permissions'),
                    __('CSV import and export'),
                ])))
                ->license('https://www.gnu.org/licenses/agpl-3.0.html')
            )
            ->organization(fn ($org) => $org
                ->name('Relaticle')
                ->url(LocalizedUrl::route('home'))
                ->logo(asset('web-app-manifest-512x512.png'))
                ->sameAs(array_filter([
                    'https://github.com/relaticle/relaticle',
                    config('services.discord.invite_url'),
                ]))
            )
            ->website(fn ($site) => $site
                ->name('Relaticle')
                ->url(LocalizedUrl::route('home'))
            );
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
