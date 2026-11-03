@php
    $assistantName = (string) config('chat.assistant_name');
    $proposalExpiry = \Carbon\CarbonInterval::minutes((int) config('chat.pending_action_expiry_minutes'))->cascade()->forHumans();
    $emailIntegrationActive = \Laravel\Pennant\Feature::active(\App\Features\EmailIntegration::class);

    $faqs = array_values(array_filter([
        ["Can {$assistantName} create, change, or delete my CRM data without my approval?", "No. {$assistantName} proposes every create, update, and delete as a card showing exactly what will change. Nothing is written until you confirm it, and you can discard any proposal. An unanswered proposal expires after {$proposalExpiry}. Reading and searching need no approval."],
        ['Do external AI agents need my approval for each change?', "No. An agent you connect over MCP, such as Claude or ChatGPT, acts with your permissions in the one workspace you approved, and its changes apply directly. Only {$assistantName} adds a review step before every write."],
        ["Does {$assistantName} send my data to OpenAI or Anthropic?", "On Relaticle Cloud, {$assistantName} runs on the model you pick from Anthropic, OpenAI, or Google. Self-hosted installs use their own provider keys or a local model through Ollama, so the destination is yours to choose. Conversation history is stored only in your Relaticle database, and Relaticle never trains on your data."],
        [
            __('What does Relaticle cost, and what happens when the trial ends?'),
            __('Relaticle Cloud is $:price a month per workspace billed yearly ($:monthly billed monthly), with unlimited users and records. Every new workspace starts a :days-day Cloud Pro trial with no card. Hosted access pauses when the trial ends without a subscription; your data stays and resumes when you subscribe.', [
                'price' => \App\Enums\Plan::Pro->yearlyPricePerMonth(),
                'monthly' => \App\Enums\Plan::Pro->monthlyPrice(),
                'days' => \App\Models\Workspace::PRO_TRIAL_DAYS,
            ]),
        ],
        ['How do I deploy Relaticle?', 'The quickest way is the hosted version at app.relaticle.com: sign up and start. To run it yourself, deploy with Docker Compose on your own server, and your data never leaves it.'],
        ['Can I customize the data model?', 'Yes. Add custom fields to any record: text, email, phone, currency, date, select, multi-select, and links to other records. You can encrypt sensitive fields. No code changes needed.'],
        ['Is Relaticle production-ready?', 'Yes. Relaticle Cloud runs in production today. Every change passes an automated test suite before it ships. Each workspace\'s data stays separate behind role-based permissions.'],
        $emailIntegrationActive
            ? ['Does Relaticle sync my email and calendar?', 'Yes. Connect a Google or Microsoft account and your email and meetings appear on the people, companies, and opportunities they involve. You can reply and send from the record, and you choose how much of each email your workspace can see.']
            : null,
        [
            __('What does the AGPL-3.0 ask of me?'),
            __('Nothing, if you use Relaticle as it ships, hosted or self-hosted. The licence only asks that a modified version served to others over a network offers its users the modified source. This is a plain summary, not legal advice; the licence text governs.'),
        ],
        ['What AI agents can I connect from outside?', 'Claude, ChatGPT, Gemini, open-source models, or your own agent. They connect over MCP (Model Context Protocol), an open standard, and can read, create, update, delete, and analyze your CRM data.'],
        ['What is MCP?', 'MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server lets external agents list companies, create people, update opportunities, analyze pipelines, and more.'],
        ["What can {$assistantName} do?", "{$assistantName} is the AI assistant built into Relaticle. Ask it anything about your CRM and it works on your data: list and search records, draft follow-ups, summarize an opportunity, create a task, update or delete a record. @-mention any record (people, companies, opportunities, tasks, notes) to scope a question. Voice input and persistent, searchable history are included."],
        ['How is Relaticle different from HubSpot or Salesforce?', "Relaticle is open source (AGPL-3.0), can be self-hosted so you own your data, ships with {$assistantName}, a built-in AI assistant, lets Claude, ChatGPT, or your own agent work in the same records over MCP, and has no per-seat pricing. It's designed for people who want AI built in and AI integration without vendor lock-in."],
    ]));
@endphp

<x-cloud::guest-layout
    :title="config('app.name') . ' - ' . __('Open-Source AI CRM for Claude and ChatGPT')"
    :description="'Open-source AI CRM for people who sell without a sales team. Work from Claude or ChatGPT, or ask '.$assistantName.', the built-in assistant. Free to self-host.'"
    :ogTitle="config('app.name') . ' - ' . __('Open-Source AI CRM for Claude and ChatGPT')"
    :ogDescription="'The open-source AI CRM for selling without a sales team. Work from Claude or ChatGPT, or ask '.$assistantName.', the built-in assistant.'"
    framed>
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
    @include('cloud::marketing.home.partials.start-building')

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->softwareApplication(fn ($app) => $app
                ->name('Relaticle')
                ->applicationCategory('BusinessApplication')
                ->applicationSubCategory('CRM')
                ->operatingSystem('Linux, macOS, Windows')
                ->description("The open-source AI CRM for selling without a sales team. Self-hosted with {$assistantName}, a built-in AI assistant (with @-mentions, approval before every write, voice, and persistent history), plus an MCP server, REST API, and custom fields. Connect any external agent: Claude, ChatGPT, Gemini, or open-source models.")
                ->url(url('/'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->name('Self-hosted')->description('Free to self-host under the AGPL-3.0 license.')->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', array_values(array_filter([
                    "{$assistantName}, a built-in AI assistant with @-mentions to records, approval before every write, and voice input",
                    'Persistent searchable conversation history',
                    'MCP server for external AI agents',
                    'REST API with full CRUD operations',
                    $emailIntegrationActive ? 'Gmail and Outlook email and calendar sync with per-email sharing controls' : null,
                    'Custom fields with per-field encryption',
                    'Self-hosted with full data ownership',
                    'Multi-workspace isolation with role-based permissions',
                    'CSV import and export',
                ])))
                ->license('https://www.gnu.org/licenses/agpl-3.0.html')
            )
            ->organization(fn ($org) => $org
                ->name('Relaticle')
                ->url(url('/'))
                ->logo(asset('web-app-manifest-512x512.png'))
                ->sameAs(array_filter([
                    'https://github.com/relaticle/relaticle',
                    config('services.discord.invite_url'),
                ]))
            )
            ->website(fn ($site) => $site
                ->name('Relaticle')
                ->url(url('/'))
            );
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
