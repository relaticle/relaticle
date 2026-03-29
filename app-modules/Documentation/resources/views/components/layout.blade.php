@php
    $documentTitle = '';
    if (isset($document)) {
        if (is_array($document)) {
            $documentTitle = $document['title'] ?? '';
        } else {
            $documentTitle = $document->title ?? '';
        }
    }
@endphp

<x-guest-layout 
    :title="!empty($documentTitle) ? $documentTitle . ' - ' . config('app.name') . ' ' . __('Documentation') : config('app.name') . ' - ' . __('Documentation')"
    :description="!empty($documentTitle) ? $documentTitle . ' - Relaticle Documentation' : 'Documentation for Relaticle - the open-source CRM built for AI agents. Self-hosted with 30 MCP tools, REST API, and 22 custom field types.'"
    :ogTitle="!empty($documentTitle) ? $documentTitle . ' - ' . config('app.name') . ' ' . __('Documentation') : config('app.name') . ' - Documentation'"
    :ogDescription="!empty($documentTitle) ? 'Learn about ' . $documentTitle . ' in the Relaticle documentation.' : 'Explore the Relaticle documentation. Installation, MCP server setup, REST API integration, custom fields, and more.'">
    @pushonce('header')
        @vite(['app-modules/Documentation/resources/js/documentation.js', 'app-modules/Documentation/resources/css/documentation.css'])
    @endpushonce

    <div class="pt-32 pb-24 md:pt-40 md:pb-32 bg-white dark:bg-black relative">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
            <div class="documentation-content">
                {{ $slot }}
            </div>
        </div>
    </div>

    @php
        $breadcrumbItems = [
            \Spatie\SchemaOrg\Schema::listItem()
                ->position(1)
                ->name('Home')
                ->item(url('/')),
            \Spatie\SchemaOrg\Schema::listItem()
                ->position(2)
                ->name('Documentation')
                ->item(route('documentation.index')),
        ];

        if (! empty($documentTitle)) {
            $breadcrumbItems[] = \Spatie\SchemaOrg\Schema::listItem()
                ->position(3)
                ->name($documentTitle)
                ->item(url()->current());
        }

        $breadcrumbs = \Spatie\SchemaOrg\Schema::breadcrumbList()
            ->itemListElement($breadcrumbItems);
    @endphp

    {!! $breadcrumbs->toScript() !!}
</x-guest-layout>
