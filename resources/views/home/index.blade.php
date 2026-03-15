<x-guest-layout
    :title="config('app.name') . ' - ' . __('The Open-Source CRM Built for AI Agents')"
    description="Open-source, self-hosted CRM with a production-grade MCP server. Connect any AI agent. 22 custom field types, REST API, team isolation."
    :ogTitle="config('app.name') . ' - Open-Source Agent-Native CRM'"
    ogDescription="Self-hosted CRM with 20 MCP tools for AI agents. Full CRUD, custom fields, schema discovery. Own your data, bring your AI."
    :ogImage="url('/images/og-image.jpg')">
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
                ->description('The open-source CRM built for AI agents. Self-hosted with a production-grade MCP server (20 tools), REST API, and 22 custom field types. Connect any AI agent -- Claude, GPT, or open-source models.')
                ->url(url('/'))
                ->offers(\Spatie\SchemaOrg\Schema::offer()->price('0')->priceCurrency('USD'))
                ->setProperty('featureList', [
                    'MCP server with 20 tools for AI agents',
                    'REST API with full CRUD operations',
                    '22 custom field types with conditional visibility and encryption',
                    'Self-hosted with full data ownership',
                    'Multi-team isolation with 5-layer authorization',
                    '1,100+ automated tests',
                    'CSV import and export',
                    'AI-powered record summaries',
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
            ->fAQPage(fn ($faq) => $faq
                ->mainEntity([
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('Is Relaticle production-ready?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('Yes. Relaticle has 1,100+ automated tests, 5-layer authorization, 56+ MCP-specific tests, and is used in production. The codebase is continuously tested with PHPStan static analysis and Pest mutation testing.')
                        ),
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('What AI agents can I connect?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('Any agent that speaks MCP (Model Context Protocol). Claude, ChatGPT, Gemini, open-source models, or your own custom agents. Relaticle\'s MCP server provides 20 tools for AI agents to read, create, update, and delete CRM data.')
                        ),
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('What is MCP?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('MCP (Model Context Protocol) is an open standard that lets AI agents interact with tools and data sources. Relaticle\'s MCP server gives agents 20 tools to work with your CRM data -- listing companies, creating contacts, updating opportunities, and more.')
                        ),
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('How is Relaticle different from HubSpot or Salesforce?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('Relaticle is self-hosted (you own your data), open-source (AGPL-3.0), has 20 MCP tools (vs HubSpot\'s 9), and has no per-seat pricing. It\'s designed for teams who want AI agent integration without vendor lock-in.')
                        ),
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('How do I deploy Relaticle?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('Deploy with Docker Compose, Laravel Forge, or any PHP 8.4+ hosting with PostgreSQL. Self-hosted means your data never leaves your server. A managed hosting option is also available at app.relaticle.com.')
                        ),
                    \Spatie\SchemaOrg\Schema::question()
                        ->name('Can I customize the data model?')
                        ->acceptedAnswer(
                            \Spatie\SchemaOrg\Schema::answer()
                                ->text('Yes. Relaticle offers 22 custom field types including text, email, phone, currency, date, select, multiselect, entity relationships, conditional visibility, and per-field encryption. No migrations or code changes needed.')
                        ),
                ])
            );
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
