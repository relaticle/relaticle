@php
    /** @var array<string, mixed> $relaticle */
    /** @var array<string, mixed> $competitor */
    $title = __('An open-source Attio alternative.');
    $metaTitle = __('Open Source Attio Alternative, Self-Hosted | Relaticle');
    $description = __('Compare Relaticle and Attio on hosting, pricing, AI and migration. Get an open-source CRM with unlimited users, or self-host it on your own infrastructure.');
    $verifiedAt = \Illuminate\Support\Facades\Date::parse($competitor['verified'])->format('F j, Y');
    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';

    $rows = [
        [__('Source code'), $relaticle['license'], $competitor['license']],
        [__('Self-hosting'), $relaticle['self_host'], $competitor['self_host']],
        [__('Cloud pricing'), $relaticle['pricing'], $competitor['pricing']],
        [__('AI and MCP'), __('Built-in AI assistant and MCP server, available in the cloud and when self-hosted'), $competitor['ai']],
        [__('Customization'), __('Custom fields, REST API and the AGPL source of everything you self-host'), $competitor['extensibility']],
    ];

    $faqs = [
        [
            __('Is Attio open source?'),
            __('No. Attio is a proprietary SaaS CRM. Relaticle publishes its CRM source code under AGPL-3.0, so you can inspect, modify and self-host it.'),
        ],
        [
            __('Can I self-host Attio?'),
            __('Attio does not offer a self-hosted version. You can self-host Relaticle without a software license fee. You manage the server, updates and backups.'),
        ],
        [
            __('Is Relaticle cheaper than Attio?'),
            __('It depends on your team and usage. Attio offers a free plan for small teams, then charges per user on paid plans. Relaticle Cloud charges per workspace, with unlimited users. Compare AI usage costs as well as the subscription.'),
        ],
        [
            __('Do both CRMs have an MCP server?'),
            __('Yes. Attio has an official MCP server on every plan. Relaticle also has a first-party MCP server, available in the cloud and when self-hosted. Both can connect supported AI tools to your CRM.'),
        ],
        [
            __('Can I move my entire Attio workspace with a CSV?'),
            __('No. CSV imports move supported records and mapped fields. They do not recreate Attio workflows, apps, permissions or synced email history. Map custom objects to supported Relaticle records and test a small sample before committing to a migration.'),
        ],
    ];
@endphp

<x-cloud::guest-layout :title="$metaTitle" :description="$description" :ogTitle="$metaTitle">
    <x-cloud::marketing.hero id="attio-intro" aria-labelledby="attio-title" align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Relaticle vs Attio') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title><span id="attio-title">{{ $title }}</span></x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('Relaticle keeps the people and companies you sell to, and your sales pipeline, in a CRM you can self-host and modify. Choose our cloud for flat workspace pricing and unlimited users.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button :href="route('login')" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" :href="route('selfHosted')">
                    {{ __('Explore self-hosting') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('No credit card required to start. Self-host without a license fee.') }}</p>
        </x-slot:aside>

        <h2 class="sr-only">{{ __('What you get with Relaticle') }}</h2>

        <div class="bg-rail border-rail grid grid-cols-1 gap-px border-t md:grid-cols-3">
            @foreach ([
                [__('Choose where it runs'), __('Our cloud or your infrastructure. You decide who operates your CRM.')],
                [__('Make the code your own'), __('Everything you self-host is :license, so you can inspect and extend any part of it.', ['license' => $relaticle['license']])],
                [__('Bring your whole team'), __('Cloud pricing covers your workspace. Adding a teammate does not add another seat charge.')],
            ] as $index => [$ownTitle, $ownBody])
                <div class="bg-surface p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $ownTitle }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $ownBody }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="comparison" aria-labelledby="comparison-title">
        <x-cloud::marketing.frame-header :label="__('The practical differences')" :lead="__('How does Relaticle compare to Attio?')">
            {{ __('Both offer AI and MCP. The bigger differences are who controls the software, where it runs and how you pay as your team grows.') }}
        </x-cloud::marketing.frame-header>

        <table id="comparison-title" class="block w-full border-collapse text-left text-sm leading-relaxed sm:table sm:table-fixed">
            <caption class="sr-only">{{ __('Relaticle and Attio: source code, hosting, pricing, AI and customization') }}</caption>
            <thead class="sr-only sm:not-sr-only">
                <tr class="border-rail border-b">
                    <th scope="col" class="type-label w-1/4 px-6 py-3 font-normal text-gray-500 md:px-10 dark:text-gray-400">{{ __('Compare') }}</th>
                    <th scope="col" class="type-label px-6 py-3 font-normal text-gray-950 md:px-8 dark:text-white">{{ $relaticle['name'] }}</th>
                    <th scope="col" class="type-label px-6 py-3 font-normal text-gray-500 md:px-8 dark:text-gray-400">{{ $competitor['name'] }}</th>
                </tr>
            </thead>
            <tbody class="divide-rail block divide-y sm:table-row-group">
                @foreach ($rows as [$label, $relaticleValue, $attioValue])
                    <tr class="grid grid-cols-2 sm:table-row">
                        <th scope="row" class="col-span-2 px-6 pb-1 pt-5 align-top text-sm font-medium text-gray-950 sm:py-5 md:px-10 dark:text-white">{{ $label }}</th>
                        <td class="px-6 pb-5 pt-1 align-top text-gray-700 sm:py-5 md:px-8 dark:text-gray-300">
                            <span aria-hidden="true" class="type-label mb-1 block text-gray-500 sm:hidden dark:text-gray-400">{{ $relaticle['name'] }}</span>
                            {{ $relaticleValue }}
                        </td>
                        <td class="px-6 pb-5 pt-1 align-top text-gray-500 sm:py-5 md:px-8 dark:text-gray-400">
                            <span aria-hidden="true" class="type-label mb-1 block text-gray-500 sm:hidden dark:text-gray-400">{{ $competitor['name'] }}</span>
                            {{ $attioValue }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="bg-surface border-rail flex flex-col gap-2 border-t px-6 py-5 text-sm text-gray-500 sm:flex-row sm:justify-between md:px-8 dark:text-gray-400">
            <p>{{ __('Compare AI allowances and usage charges too. Self-hosting requires infrastructure and maintenance.') }}</p>
            <a href="{{ route('pricing') }}" class="{{ $linkClass }} shrink-0">{{ __('See Relaticle pricing') }}</a>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="fit" aria-labelledby="fit-title">
        <x-cloud::marketing.frame-header :label="__('Fit')" :lead="__('Which CRM fits your team?')">
            {{ __('Switching only makes sense if the tradeoffs work in your favor.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-2">
            <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                <h3 id="fit-title" class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Choose Relaticle when...') }}</h3>
                <ul class="divide-rail border-rail type-prose divide-y border-y text-gray-700 dark:text-gray-300">
                    <li class="py-2.5">{{ __('You want control over hosting, backups and the code, or a path to migrate later.') }}</li>
                    <li class="py-2.5">{{ __('You manage people, companies and sales opportunities, with tasks and notes alongside them.') }}</li>
                    <li class="py-2.5">{{ __('You want unlimited teammates on a flat cloud subscription, or a self-hosted install with no license fee.') }}</li>
                </ul>
                <a href="{{ route('ai') }}" class="{{ $linkClass }} mt-auto w-fit text-sm">{{ __('Explore Relaticle AI') }}</a>
            </div>
            <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Attio may fit better when...') }}</h3>
                <ul class="divide-rail border-rail type-prose divide-y border-y text-gray-700 dark:text-gray-300">
                    <li class="py-2.5">{{ __('Your team relies on automatic enrichment, synced email and calendar activity, and a large app marketplace.') }}</li>
                    <li class="py-2.5">{{ __('Your workflow needs custom objects and relationships beyond Relaticle\'s companies, people, opportunities, tasks and notes.') }}</li>
                    <li class="py-2.5">{{ __('Attio\'s free plan already covers your team, and self-hosting or source access does not matter to you.') }}</li>
                </ul>
                <a href="{{ $competitor['source_urls']['apps'] }}" class="{{ $linkClass }} mt-auto w-fit text-sm">{{ __('Check Attio apps') }}</a>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="migration" aria-labelledby="migration-title">
        <x-cloud::marketing.frame-header :label="__('A practical migration path')" :lead="__('Try a small move first.')">
            {{ __('Bring a sample of your Attio records into Relaticle and check the fields, relationships and workflow before moving the rest of your team.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface p-6 md:p-10 lg:col-span-4">
                <h3 id="migration-title" class="text-lg font-medium text-gray-950 dark:text-white">{{ __('What maps to what') }}</h3>
                <dl class="divide-rail border-rail mt-4 divide-y border-y text-sm">
                    @foreach ([[__('Companies'), __('Companies')], [__('People'), __('People')], [__('Deals'), __('Opportunities')]] as [$from, $to])
                        <div class="grid grid-cols-2 gap-4 py-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('Attio :record', ['record' => $from]) }}</dt>
                            <dd class="flex items-center gap-2 font-medium text-gray-950 dark:text-white"><x-lucide-arrow-right class="size-4 shrink-0 text-gray-400" aria-hidden="true"/>{{ $to }}</dd>
                        </div>
                    @endforeach
                </dl>
                <a href="{{ url('/help/import') }}" class="{{ $linkClass }} mt-6 inline-block text-sm">{{ __('Read the import guide') }}</a>
            </div>

            <ol class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-2 lg:col-span-8">
                @foreach ([
                    [__('Export the fields you need'), __('In Attio, include the columns you need and review the view filters. Click Save for everyone, then export the view as CSV.')],
                    [__('Prepare your fields and relationships'), __('Create matching custom fields in Relaticle. Start with companies, then people, then deals. Use company domains and people\'s email addresses to link records.')],
                    [__('Map columns and review a sample'), __('Import five to ten rows first. Attio deals become Relaticle opportunities. Keep Attio record IDs out of Relaticle\'s Record ID mapping.')],
                    [__('Check the result before importing more'), __('Review which records will be created, updated or skipped. Check the imported relationships and field values. Then repeat with your remaining records.')],
                ] as $index => [$heading, $body])
                    <li class="bg-surface p-6 md:p-10">
                        <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $heading }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $body }}</p>
                    </li>
                @endforeach
            </ol>
        </div>

        <div class="bg-rail border-rail grid grid-cols-1 gap-px border-t md:grid-cols-3" aria-labelledby="export-limits">
            <div class="bg-surface p-6 md:col-span-3 md:px-10 md:py-6">
                <h3 id="export-limits" class="text-base font-medium text-gray-950 dark:text-white">{{ __('What an Attio export leaves out') }}</h3>
            </div>
            <p class="bg-surface type-prose p-6 text-gray-500 md:px-10 dark:text-gray-400"><strong class="font-medium text-gray-950 dark:text-white">{{ __('Enriched values are excluded.') }}</strong> {{ __('Attio exports manually added data, but enriched values appear as empty cells.') }} <a href="{{ $competitor['source_urls']['exports'] }}" class="{{ $linkClass }}">{{ __('Export rules') }}</a></p>
            <p class="bg-surface type-prose p-6 text-gray-500 md:px-10 dark:text-gray-400"><strong class="font-medium text-gray-950 dark:text-white">{{ __('Email history is not included.') }}</strong> {{ __('Attio\'s workspace backup excludes emails. Keep a separate plan for that history.') }} <a href="{{ $competitor['source_urls']['backup'] }}" class="{{ $linkClass }}">{{ __('Backup limits') }}</a></p>
            <p class="bg-surface type-prose p-6 text-gray-500 md:px-10 dark:text-gray-400"><strong class="font-medium text-gray-950 dark:text-white">{{ __('Your setup needs rebuilding.') }}</strong> {{ __('CSV imports do not recreate workflows, apps or permissions. Check any custom objects, automations and integrations your team relies on.') }}</p>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="attio-faq" aria-labelledby="faq-title">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 id="faq-title" class="type-title text-gray-950 dark:text-white">{{ __('Before you switch') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ __('Straight answers to common questions about leaving Attio.') }}</p>
                </div>
            </div>

            <div class="bg-surface divide-rail divide-y px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                @foreach ($faqs as [$question, $answer])
                    <details class="group py-5" @if ($loop->first) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold text-gray-900 [&::-webkit-details-marker]:hidden dark:text-white">
                            {{ $question }}
                            <x-lucide-plus class="size-5 shrink-0 text-gray-400 transition-transform group-open:rotate-45 motion-reduce:transition-none" aria-hidden="true"/>
                        </summary>
                        <p class="type-prose mt-3 pr-8 text-gray-600 dark:text-gray-400">{{ $answer }}</p>
                    </details>
                @endforeach
            </div>
        </div>

        <div class="bg-surface border-rail border-t px-6 py-5 text-sm text-gray-500 md:px-8 dark:text-gray-400">
            <p>{{ __('Written by Relaticle. Facts verified :date.', ['date' => $verifiedAt]) }}</p>
            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <span>{{ __('Primary sources:') }}</span>
                @foreach ([
                    [$relaticle['source_urls']['repository'], __('Relaticle source code')],
                    [$competitor['source_urls']['pricing'], __('Attio pricing')],
                    [$competitor['source_urls']['mcp'], __('Attio MCP')],
                    [$competitor['source_urls']['apps'], __('Attio apps')],
                ] as [$href, $label])
                    <a href="{{ $href }}" class="{{ $linkClass }}">{{ $label }}</a>
                @endforeach
            </p>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Make your next CRM an informed choice.')" :continuation="__('Start a workspace, bring a few records, and try your actual sales workflow.')">
        <x-cloud::marketing.button :href="route('login')" data-analytics-intent="signup" data-analytics-placement="footer">{{ __('Start for free') }}</x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" :href="route('contact')">{{ __('Talk through your migration') }}</x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn (\Spatie\SchemaOrg\WebPage $page): \Spatie\SchemaOrg\WebPage => $page
                ->name($title)
                ->description($description)
                ->url(url()->current())
                ->dateModified($competitor['verified'])
                ->about([
                    \Spatie\SchemaOrg\Schema::softwareApplication()->name($relaticle['name'])->url($relaticle['source_urls']['website'])->applicationCategory('BusinessApplication'),
                    \Spatie\SchemaOrg\Schema::softwareApplication()->name($competitor['name'])->url($competitor['source_urls']['website'])->applicationCategory('BusinessApplication'),
                ]))
            ->fAQPage(fn (\Spatie\SchemaOrg\FAQPage $page): \Spatie\SchemaOrg\FAQPage => $page
                ->mainEntity(collect($faqs)->map(fn (array $faq): \Spatie\SchemaOrg\Question => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn (\Spatie\SchemaOrg\BreadcrumbList $list): \Spatie\SchemaOrg\BreadcrumbList => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Attio alternative'))->item(url()->current()),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
