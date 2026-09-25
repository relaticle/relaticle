@php
    /** @var array<string, mixed> $relaticle */
    /** @var array<string, mixed> $competitor */
    $title = __('An open-source Attio alternative.');
    $metaTitle = __('Open Source Attio Alternative, Self-Hosted | Relaticle');
    $description = __('Compare Relaticle and Attio on hosting, pricing, AI and migration. Get an open-source CRM with unlimited users, or self-host it on your own infrastructure.');
    $verifiedAt = \Illuminate\Support\Facades\Date::parse($competitor['verified'])->format('F j, Y');

    $rows = [
        [__('Source code'), $relaticle['license'], $competitor['license']],
        [__('Self-hosting'), $relaticle['self_host'], $competitor['self_host']],
        [__('Cloud pricing'), $relaticle['pricing'], $competitor['pricing']],
        [__('AI and MCP'), __('Built-in AI assistant and MCP server, available in the cloud and when self-hosted'), $competitor['ai']],
        [__('Customization'), __('Custom fields, REST API and access to the full source code'), $competitor['extensibility']],
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

<x-guest-layout :title="$metaTitle" :description="$description" :ogTitle="$metaTitle">
    <div class="bg-white text-gray-950 dark:bg-gray-950 dark:text-white">
        <section id="attio-intro" aria-labelledby="attio-title" class="mx-auto max-w-6xl px-6 pt-32 pb-16 sm:pt-40 sm:pb-20 lg:px-8">
            <div class="grid items-center gap-12 lg:grid-cols-5 lg:gap-16">
                <div class="lg:col-span-3">
                    <p class="mb-6 flex items-center gap-2 text-xs font-semibold tracking-widest text-gray-500 uppercase dark:text-gray-400">
                        <x-ri-arrow-left-right-line class="size-4 text-primary dark:text-primary-400" aria-hidden="true"/>
                        {{ __('Relaticle vs Attio') }}
                    </p>
                    <h1 id="attio-title" class="font-display text-4xl leading-tight font-bold tracking-tight sm:text-5xl lg:text-6xl">
                        {{ __('An open-source') }}
                        <span class="block text-primary dark:text-primary-400">{{ __('Attio alternative.') }}</span>
                    </h1>
                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-gray-600 dark:text-gray-400">
                        {{ __('Relaticle brings your contacts, companies and sales pipeline together in a CRM you can self-host and modify. Choose our cloud for flat workspace pricing and unlimited users.') }}
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <x-marketing.button :href="route('login')" icon-trailing="ri-arrow-right-line">
                            {{ __('Start for free') }}
                        </x-marketing.button>
                        <x-marketing.button variant="secondary" :href="route('selfHosted')">
                            {{ __('Explore self-hosting') }}
                        </x-marketing.button>
                    </div>
                    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No credit card required to start. Self-host without a license fee.') }}
                    </p>
                </div>

                <div class="overflow-hidden rounded-2xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] lg:col-span-2">
                    <div class="border-b border-[var(--surface-card-border)] px-6 py-5 sm:px-8">
                        <p class="text-xs font-semibold tracking-widest text-primary uppercase dark:text-primary-400">{{ __('The ownership difference') }}</p>
                        <p class="mt-2 font-display text-2xl font-bold tracking-tight">{{ __('Your CRM. Your choice.') }}</p>
                    </div>
                    <dl class="divide-y divide-[var(--surface-card-border)] px-6 sm:px-8">
                        <div class="py-6">
                            <dt class="flex items-center gap-4 text-sm font-semibold"><x-ri-server-line class="size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/>{{ __('Choose where it runs') }}</dt>
                            <dd class="mt-1 pl-9 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Our cloud or your infrastructure. You decide who operates your CRM.') }}</dd>
                        </div>
                        <div class="py-6">
                            <dt class="flex items-center gap-4 text-sm font-semibold"><x-ri-code-s-slash-line class="size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/>{{ __('Make the code your own') }}</dt>
                            <dd class="mt-1 pl-9 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Inspect and extend the full codebase under :license.', ['license' => $relaticle['license']]) }}</dd>
                        </div>
                        <div class="py-6">
                            <dt class="flex items-center gap-4 text-sm font-semibold"><x-ri-team-line class="size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/>{{ __('Bring your whole team') }}</dt>
                            <dd class="mt-1 pl-9 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Cloud pricing covers your workspace. Adding a teammate does not add another seat charge.') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </section>

        <nav aria-label="{{ __('On this page') }}" class="border-y border-[var(--surface-card-border)]">
            <div class="mx-auto flex max-w-6xl flex-wrap gap-x-8 gap-y-3 px-6 py-5 text-sm font-medium text-gray-600 sm:gap-x-10 lg:px-8 dark:text-gray-400">
                <a href="#comparison" class="hover:text-primary dark:hover:text-primary-400">{{ __('Compare the details') }}</a>
                <a href="#fit" class="hover:text-primary dark:hover:text-primary-400">{{ __('Find your fit') }}</a>
                <a href="#migration" class="hover:text-primary dark:hover:text-primary-400">{{ __('Plan your move') }}</a>
                <a href="#attio-faq" class="hover:text-primary dark:hover:text-primary-400">{{ __('Common questions') }}</a>
            </div>
        </nav>

        <section id="comparison" aria-labelledby="comparison-title" class="mx-auto max-w-6xl scroll-mt-24 px-6 py-16 sm:py-20 lg:px-8">
            <div class="mb-8 max-w-2xl">
                <p class="mb-3 text-xs font-semibold tracking-widest text-primary uppercase dark:text-primary-400">{{ __('The practical differences') }}</p>
                <h2 id="comparison-title" class="font-display text-3xl font-bold tracking-tight sm:text-4xl">{{ __('How does Relaticle compare to Attio?') }}</h2>
                <p class="mt-4 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Both offer AI and MCP. The bigger differences are who controls the software, where it runs and how you pay as your team grows.') }}</p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-[var(--surface-card-border)]">
                <table role="table" class="block w-full table-fixed border-collapse text-left text-sm leading-relaxed sm:table">
                    <caption class="sr-only">{{ __('Relaticle and Attio: source code, hosting, pricing, AI and customization') }}</caption>
                    <thead role="rowgroup" class="sr-only sm:not-sr-only">
                        <tr role="row" class="border-b border-[var(--surface-card-border)] bg-[var(--surface-card-bg)]">
                            <th role="columnheader" scope="col" class="w-1/4 px-6 py-5 font-medium text-gray-500 dark:text-gray-400">{{ __('Compare') }}</th>
                            <th role="columnheader" scope="col" class="bg-primary-50 px-6 py-5 font-semibold text-primary dark:bg-primary-950 dark:text-primary-300">{{ $relaticle['name'] }}</th>
                            <th role="columnheader" scope="col" class="px-6 py-5 font-semibold">{{ $competitor['name'] }}</th>
                        </tr>
                    </thead>
                    <tbody role="rowgroup" class="block divide-y divide-[var(--surface-card-border)] sm:table-row-group">
                        @foreach ($rows as [$label, $relaticleValue, $attioValue])
                            <tr role="row" class="grid grid-cols-2 sm:table-row">
                                <th role="rowheader" scope="row" class="col-span-2 bg-[var(--surface-card-bg)] px-4 py-3 align-top font-semibold sm:bg-transparent sm:px-6 sm:py-6 sm:font-medium">{{ $label }}</th>
                                <td role="cell" class="bg-primary-50/50 px-4 py-4 align-top text-gray-700 sm:px-6 sm:py-6 dark:bg-primary-950/30 dark:text-gray-300">
                                    <span aria-hidden="true" class="mb-2 block text-xs font-semibold text-primary sm:hidden dark:text-primary-300">{{ $relaticle['name'] }}</span>
                                    {{ $relaticleValue }}
                                </td>
                                <td role="cell" class="px-4 py-4 align-top text-gray-600 sm:px-6 sm:py-6 dark:text-gray-400">
                                    <span aria-hidden="true" class="mb-2 block text-xs font-semibold text-gray-950 sm:hidden dark:text-white">{{ $competitor['name'] }}</span>
                                    {{ $attioValue }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-5 flex flex-col gap-3 text-sm text-gray-500 sm:flex-row sm:justify-between dark:text-gray-400">
                <p>{{ __('Compare AI allowances and usage charges too. Self-hosting requires infrastructure and maintenance.') }}</p>
                <a href="{{ route('pricing') }}" class="shrink-0 font-medium text-primary underline underline-offset-4 dark:text-primary-400">{{ __('See Relaticle pricing') }}</a>
            </div>
        </section>

        <section id="fit" aria-labelledby="fit-title" class="scroll-mt-24 border-y border-[var(--surface-card-border)] bg-[var(--surface-card-bg)]">
            <div class="mx-auto max-w-6xl px-6 py-16 sm:py-20 lg:px-8">
                <h2 id="fit-title" class="font-display text-3xl font-bold tracking-tight sm:text-4xl">{{ __('Which CRM fits your team?') }}</h2>
                <p class="mt-4 max-w-2xl leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Start with the work your team needs to do. Switching only makes sense if the tradeoffs work in your favor.') }}</p>
                <div class="mt-10 grid gap-10 md:grid-cols-2 md:gap-16">
                    <div>
                        <h3 class="font-display text-xl font-bold text-primary dark:text-primary-400">{{ __('Choose Relaticle when...') }}</h3>
                        <ul class="mt-5 space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            <li class="flex gap-3"><x-ri-checkbox-circle-fill class="mt-0.5 size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/><span>{{ __('You want control over hosting, backups and the source code behind your CRM.') }}</span></li>
                            <li class="flex gap-3"><x-ri-checkbox-circle-fill class="mt-0.5 size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/><span>{{ __('You manage people, companies and sales opportunities, with custom fields for your process.') }}</span></li>
                            <li class="flex gap-3"><x-ri-checkbox-circle-fill class="mt-0.5 size-5 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/><span>{{ __('You want unlimited teammates on a flat cloud subscription, or can operate your own installation.') }}</span></li>
                        </ul>
                        <a href="{{ route('ai') }}" class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-primary underline underline-offset-4 dark:text-primary-400">{{ __('Explore Relaticle AI') }}<x-ri-arrow-right-line class="size-4" aria-hidden="true"/></a>
                    </div>
                    <div>
                        <h3 class="font-display text-xl font-bold">{{ __('Attio may fit better when...') }}</h3>
                        <ul class="mt-5 space-y-4 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                            <li class="flex gap-3"><x-ri-arrow-right-line class="mt-0.5 size-5 shrink-0 text-gray-400" aria-hidden="true"/><span>{{ __('Your team relies on automatic enrichment, synced email and calendar activity, or Attio-specific apps.') }}</span></li>
                            <li class="flex gap-3"><x-ri-arrow-right-line class="mt-0.5 size-5 shrink-0 text-gray-400" aria-hidden="true"/><span>{{ __('Your workflow needs custom objects and relationships beyond Relaticle\'s supported record types.') }}</span></li>
                            <li class="flex gap-3"><x-ri-arrow-right-line class="mt-0.5 size-5 shrink-0 text-gray-400" aria-hidden="true"/><span>{{ __('Attio\'s free plan already covers your team, and self-hosting or source access would not help you.') }}</span></li>
                        </ul>
                        <a href="{{ $competitor['source_urls']['apps'] }}" class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-gray-700 underline underline-offset-4 dark:text-gray-300">{{ __('Check Attio apps') }}<x-ri-arrow-right-up-line class="size-4" aria-hidden="true"/></a>
                    </div>
                </div>
            </div>
        </section>

        <section id="migration" aria-labelledby="migration-title" class="mx-auto max-w-6xl scroll-mt-24 px-6 py-16 sm:py-20 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-5 lg:gap-16">
                <div class="lg:col-span-2">
                    <p class="mb-3 text-xs font-semibold tracking-widest text-primary uppercase dark:text-primary-400">{{ __('A practical migration path') }}</p>
                    <h2 id="migration-title" class="font-display text-3xl font-bold tracking-tight sm:text-4xl">{{ __('Try a small move first.') }}</h2>
                    <p class="mt-4 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Bring a sample of your Attio records into Relaticle. Check the fields, relationships and workflow before moving the rest of your team.') }}</p>
                    <dl class="mt-8 divide-y divide-[var(--surface-card-border)] rounded-xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-5 text-sm">
                        @foreach ([[__('Companies'), __('Companies')], [__('People'), __('People')], [__('Deals'), __('Opportunities')]] as [$from, $to])
                            <div class="grid grid-cols-2 gap-4 py-4">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('Attio :record', ['record' => $from]) }}</dt>
                                <dd class="flex items-center gap-2 font-medium"><x-ri-arrow-right-line class="size-4 shrink-0 text-primary dark:text-primary-400" aria-hidden="true"/>{{ $to }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <a href="{{ url('/help/import') }}" class="mt-6 inline-flex items-center gap-2 text-sm font-medium text-primary underline underline-offset-4 dark:text-primary-400">{{ __('Read the import guide') }}<x-ri-arrow-right-line class="size-4" aria-hidden="true"/></a>
                </div>

                <ol class="space-y-8 lg:col-span-3">
                    @foreach ([
                        [__('Export the fields you need'), __('In Attio, include the columns you need and review the view filters. Click Save for everyone, then export the view as CSV.')],
                        [__('Prepare your fields and relationships'), __('Create matching custom fields in Relaticle. Start with companies, then people, then deals. Use company domains and contact emails to link related records.')],
                        [__('Map columns and review a sample'), __('Import five to ten rows first. Attio deals become Relaticle opportunities. Keep Attio record IDs out of Relaticle\'s Record ID mapping.')],
                        [__('Check the result before importing more'), __('Review which records will be created, updated or skipped. Check the imported relationships and field values. Then repeat with your remaining records.')],
                    ] as [$heading, $body])
                        <li class="flex gap-4 sm:gap-5">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full border border-primary-200 bg-primary-50 text-sm font-semibold text-primary dark:border-primary-800 dark:bg-primary-950 dark:text-primary-300" aria-hidden="true">{{ $loop->iteration }}</span>
                            <div>
                                <h3 class="pt-0.5 text-base font-semibold">{{ $heading }}</h3>
                                <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $body }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            <aside aria-labelledby="export-limits" class="mt-12 rounded-2xl border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] p-6 sm:p-8">
                <h3 id="export-limits" class="flex items-center gap-2 text-base font-semibold"><x-ri-information-fill class="size-5 text-primary dark:text-primary-400" aria-hidden="true"/>{{ __('What an Attio export leaves out') }}</h3>
                <div class="mt-5 grid gap-5 text-sm leading-relaxed text-gray-600 md:grid-cols-3 md:gap-8 dark:text-gray-400">
                    <p><strong class="font-semibold text-gray-950 dark:text-white">{{ __('Enriched values are excluded.') }}</strong> {{ __('Attio exports manually added data, but enriched values appear as empty cells.') }} <a href="{{ $competitor['source_urls']['exports'] }}" class="text-primary underline underline-offset-4 dark:text-primary-400">{{ __('Export rules') }}</a></p>
                    <p><strong class="font-semibold text-gray-950 dark:text-white">{{ __('Email history is not included.') }}</strong> {{ __('Attio\'s workspace backup excludes emails. Keep a separate plan for that history.') }} <a href="{{ $competitor['source_urls']['backup'] }}" class="text-primary underline underline-offset-4 dark:text-primary-400">{{ __('Backup limits') }}</a></p>
                    <p><strong class="font-semibold text-gray-950 dark:text-white">{{ __('Your setup needs rebuilding.') }}</strong> {{ __('CSV imports do not recreate workflows, apps or permissions. Check any custom objects before deciding to switch.') }}</p>
                </div>
            </aside>
        </section>

        <section id="attio-faq" aria-labelledby="faq-title" class="mx-auto max-w-6xl scroll-mt-24 border-t border-[var(--surface-card-border)] px-6 py-16 sm:py-20 lg:px-8">
            <div class="grid gap-8 lg:grid-cols-5 lg:gap-16">
                <div class="lg:col-span-2">
                    <h2 id="faq-title" class="font-display text-3xl font-bold tracking-tight sm:text-4xl">{{ __('Before you switch') }}</h2>
                    <p class="mt-4 leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Straight answers to common questions about leaving Attio.') }}</p>
                </div>
                <div class="divide-y divide-[var(--surface-card-border)] border-y border-[var(--surface-card-border)] lg:col-span-3">
                    @foreach ($faqs as [$question, $answer])
                        <details class="group py-5" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                                {{ $question }}
                                <x-ri-add-line class="size-5 shrink-0 text-gray-400 transition-transform group-open:rotate-45 motion-reduce:transition-none" aria-hidden="true"/>
                            </summary>
                            <p class="mt-3 pr-8 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $answer }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        <section aria-labelledby="try-title" class="mx-auto max-w-6xl px-6 pb-16 sm:pb-20 lg:px-8">
            <div class="rounded-2xl border border-primary-100 bg-primary-50 px-6 py-12 text-center sm:px-12 dark:border-primary-900 dark:bg-primary-950/40">
                <h2 id="try-title" class="font-display text-3xl font-bold tracking-tight sm:text-4xl">{{ __('Make your next CRM an informed choice.') }}</h2>
                <p class="mx-auto mt-4 max-w-xl leading-relaxed text-gray-600 dark:text-gray-400">{{ __('Start a workspace, bring a few records and try your actual sales workflow. See whether Relaticle fits before you move.') }}</p>
                <div class="mt-7 flex flex-col justify-center gap-3 sm:flex-row">
                    <x-marketing.button :href="route('login')" icon-trailing="ri-arrow-right-line">{{ __('Start for free') }}</x-marketing.button>
                    <x-marketing.button variant="secondary" :href="route('contact')">{{ __('Talk through your migration') }}</x-marketing.button>
                </div>
            </div>
            <div class="mt-8 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                <p>{{ __('Written by Relaticle. Facts verified :date.', ['date' => $verifiedAt]) }}</p>
                <p class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
                    <span>{{ __('Primary sources:') }}</span>
                    @foreach ([
                        [$relaticle['source_urls']['repository'], __('Relaticle source code')],
                        [$competitor['source_urls']['pricing'], __('Attio pricing')],
                        [$competitor['source_urls']['mcp'], __('Attio MCP')],
                        [$competitor['source_urls']['apps'], __('Attio apps')],
                    ] as [$href, $label])
                        <a href="{{ $href }}" class="underline underline-offset-4 hover:text-primary dark:hover:text-primary-400">{{ $label }}</a>
                    @endforeach
                </p>
            </div>
        </section>
    </div>

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
</x-guest-layout>
