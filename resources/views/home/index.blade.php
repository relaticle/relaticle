<x-guest-layout
    :title="config('app.name') . ' - ' . __('The Open-Source CRM Built for AI Agents')"
    description="Open-source, self-hosted CRM with a production-grade MCP server. Connect any AI agent. 22 custom field types, REST API, team isolation."
    :ogTitle="config('app.name') . ' - Open-Source Agent-Native CRM'"
    ogDescription="Self-hosted CRM with 20 MCP tools for AI agents. Full CRUD, custom fields, schema discovery. Own your data, bring your AI."
    :ogImage="url('/images/og-image.jpg')">
    @include('home.partials.hero')
    @include('home.partials.features')
    @include('home.partials.community')
    @include('home.partials.start-building')

    @push('header')
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "SoftwareApplication",
                "name": "Relaticle",
                "applicationCategory": "BusinessApplication",
                "applicationSubCategory": "CRM",
                "operatingSystem": "Linux, macOS, Windows",
                "description": "Open-source, self-hosted CRM with a production-grade MCP server. Connect any AI agent with 20 tools. 22 custom field types, REST API, and multi-team isolation.",
                "url": "{{ url('/') }}",
                "offers": {
                    "@type": "Offer",
                    "price": "0",
                    "priceCurrency": "USD"
                },
                "featureList": [
                    "MCP server with 20 tools for AI agents",
                    "REST API with full CRUD operations",
                    "22 custom field types with conditional visibility",
                    "Self-hosted with full data ownership",
                    "Multi-team isolation with 5-layer authorization",
                    "CSV import and export",
                    "AI-powered record summaries"
                ],
                "license": "https://www.gnu.org/licenses/agpl-3.0.html"
            },
            {
                "@type": "Organization",
                "name": "Relaticle",
                "url": "{{ url('/') }}",
                "logo": "{{ asset('favicon.svg') }}",
                "sameAs": [
                    "https://github.com/relaticle/relaticle"
                ]
            },
            {
                "@type": "WebSite",
                "name": "Relaticle",
                "url": "{{ url('/') }}"
            }
        ]
    }
    </script>
    @endpush
</x-guest-layout>
