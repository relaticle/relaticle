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
</x-guest-layout>
