@php
    $faqs = [
        ['Is Relaticle production-ready?', 'Yes. Relaticle has 1,100+ automated tests, 5-layer authorization, 56+ MCP-specific tests, and is used in production. The codebase is continuously tested with PHPStan static analysis and Pest mutation testing.'],
        ['What can the built-in AI chat do?', 'Ask anything about your CRM and the chat works on your data: list and search records, draft follow-ups, summarize a deal, create a task, update or delete a record. @-mention any record (people, companies, deals, tasks, notes) to scope a question. Voice input, persistent searchable history, and dashboard insight cards are included.'],
        ['Can the AI chat delete or change my CRM data without my approval?', 'No. Destructive operations (delete, update existing records) show an approval card with Approve and Reject buttons — nothing happens until you click. Approved destructive actions can be undone for 5 seconds via a toast. Read-only and create operations don\'t require approval.'],
        ['Does the built-in chat send my data to OpenAI or Anthropic?', 'Inference runs through whichever AI provider your team configures (Anthropic Claude, Google Gemini, or any OpenAI-compatible endpoint). Conversation history is stored only in your Relaticle database — Relaticle never trains on your data. Self-hosted teams supply their own provider keys, so the destination is yours to choose.'],
        ['What AI agents can I connect from outside?', 'Any agent that speaks MCP (Model Context Protocol). Claude, ChatGPT, Gemini, open-source models, or your own custom agents. Relaticle\'s MCP server provides 30 tools for external AI agents to read, create, update, and delete CRM data — the same toolset the built-in chat uses internally.'],
        ['What is MCP?', 'MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server gives external agents 30 tools to work with your CRM data — listing companies, creating contacts, updating opportunities, and more.'],
        ['How is Relaticle different from HubSpot or Salesforce?', 'Relaticle is self-hosted (you own your data), open-source (AGPL-3.0), ships with both a built-in AI chat and 30 MCP tools for any external agent, and has no per-seat pricing. It\'s designed for teams who want AI built in and AI integration both — without vendor lock-in.'],
        ['How do I deploy Relaticle?', 'Deploy with Docker Compose, Laravel Forge, or any PHP 8.4+ hosting with PostgreSQL. Self-hosted means your data never leaves your server. A managed hosting option is also available at app.relaticle.com.'],
        ['Can I customize the data model?', 'Yes. Relaticle offers 22 custom field types including text, email, phone, currency, date, select, multiselect, entity relationships, conditional visibility, and per-field encryption. No migrations or code changes needed.'],
    ];
@endphp

<x-guest-layout
    :title="config('app.name') . ' - ' . __('The Open-Source CRM Built for AI Agents')"
    description="Open-source, self-hosted CRM with a built-in AI chat agent and a production-grade MCP server. @-mention records, safe approvals, voice input, persistent history. 22 custom field types, REST API, team isolation."
    :ogTitle="config('app.name') . ' - Open-Source Agent-Native CRM'"
    ogDescription="Open-source CRM with a built-in AI chat — @-mention records, safe approvals on destructive ops, voice input, persistent history. Plus 30 MCP tools for external agents. Self-hosted, you own your data."
    :ogImage="url('/images/open-graph.jpg')">
    @push('header')
        @vite('resources/js/motion.js')
    @endpush

    @include('home.partials.hero')
    @include('home.partials.features')
    @include('home.partials.community')
    @include('home.partials.faq')
    @include('home.partials.start-building')

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->softwareApplication(fn ($app) => $app
                ->name('Relaticle')
                ->applicationCategory('BusinessApplication')
                ->applicationSubCategory('CRM')
                ->operatingSystem('Linux, macOS, Windows')
                ->description('The open-source CRM built for AI agents. Self-hosted with a built-in AI chat (with @-mentions, safe approvals, voice, and persistent history) plus a production-grade MCP server (30 tools), REST API, and 22 custom field types. Connect any external agent -- Claude, GPT, Gemini, or open-source models.')
                ->url(url('/'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', [
                    'Built-in AI chat with @-mentions to records, safe approvals on destructive actions, undo, and voice input',
                    'Persistent searchable conversation history',
                    'Dashboard AI insight cards (overdue tasks, recent wins, pipeline)',
                    'MCP server with 30 tools for external AI agents',
                    'REST API with full CRUD operations',
                    '22 custom field types with conditional visibility and encryption',
                    'Self-hosted with full data ownership',
                    'Multi-team isolation with 5-layer authorization',
                    '1,100+ automated tests',
                    'CSV import and export',
                ])
                ->license('https://www.gnu.org/licenses/agpl-3.0.html')
            )
            ->organization(fn ($org) => $org
                ->name('Relaticle')
                ->url(url('/'))
                ->logo(asset('favicon.svg'))
                ->sameAs(array_filter([
                    'https://github.com/relaticle/relaticle',
                    config('services.discord.invite_url'),
                ]))
            )
            ->website(fn ($site) => $site
                ->name('Relaticle')
                ->url(url('/'))
            )
            ->fAQPage(function ($faq) use ($faqs) {
                return $faq->mainEntity(array_map(fn ($item) => \Spatie\SchemaOrg\Schema::question()
                    ->name($item[0])
                    ->acceptedAnswer(
                        \Spatie\SchemaOrg\Schema::answer()->text($item[1])
                    ), $faqs));
            });
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
