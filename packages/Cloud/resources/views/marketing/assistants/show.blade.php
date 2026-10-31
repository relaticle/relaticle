@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $docsActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
    $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;
    $pageUrl = route($assistant->routeName());
    $name = $assistant->label();
    $sibling = $assistant->sibling();

    $steps = match ($assistant) {
        \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude => [
            [__('Add the connector'), __('In Claude, open Connectors and add a custom connector with this URL.'), $mcpUrl],
            [__('Approve access'), __('Claude sends you to Relaticle to sign in. Approve access and pick the workspace it may use.'), null],
            [__('Ask'), __('Back in Claude, ask about your pipeline or tell it what to add.'), null],
        ],
        \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT => [
            [__('Install the plugin'), __('Open the Relaticle plugin in the ChatGPT plugin directory and click Install plugin.'), null],
            [__('Approve access'), __('ChatGPT sends you to Relaticle to sign in. Approve access and pick the workspace it may use.'), null],
            [__('Ask'), __('Start a message with @Relaticle, then ask about your pipeline or tell it what to add.'), null],
        ],
    };

    $selfHostedAnswer = match ($assistant) {
        \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::Claude => __('A self-hosted install includes the same MCP server on your own domain. The MCP server guide covers how a client connects to it.'),
        \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT => __('The plugin in the ChatGPT directory connects to Relaticle Cloud. A self-hosted install includes the same MCP server on your own domain, and the MCP server guide covers how a client connects to it.'),
    };

    $title = __('CRM for :name: run your pipeline from chat', ['name' => $name]).' - Relaticle';
    $description = __(
        'Connect :name to Relaticle and work your target companies from the chat: search the pipeline, add people, log tasks and notes. For founder-led sales.',
        ['name' => $name]
    );

    $prompts = [
        ['ri-line-chart-line', __('What is in my pipeline this month?'), __('It totals your opportunities by stage or by company.')],
        ['ri-user-add-line', __('Add Dana Reyes at Northwind Labs and link her to the company.'), __('It creates the person and the company, and links the two.')],
        ['ri-task-line', __('Create a task to send the proposal on Friday, on the Northwind opportunity.'), __('It creates the task and attaches it to the opportunity.')],
        ['ri-sticky-note-line', __('What did we agree with Northwind last time?'), __('It reads the notes on the company and answers from them.')],
    ];

    $exampleStages = [
        [__('Qualification'), 4, '$48,000'],
        [__('Proposal/Price Quote'), 3, '$72,500'],
        [__('Negotiation/Review'), 2, '$61,000'],
    ];

    $fit = [
        ['ri-file-list-line', __('A named list, not a lead funnel'), __('Import your target companies from a CSV, or have :name add them as you work. Each company keeps its people, opportunities, tasks and notes together.', ['name' => $name])],
        ['ri-stack-line', __('Fields that fit your deals'), __('Add custom fields for the dates, criteria and stages your deals turn on. :name reads and sets them by name, option labels included.', ['name' => $name])],
        ['ri-price-tag-3-line', __('One price for the whole team'), __('Unlimited users on one flat price. Nothing changes on the bill when a second person joins the deal.')],
    ];

    $caveats = [
        ['ri-edit-line', __('Changes apply directly'), __('A connected assistant writes straight to your records, so rely on its own confirmation prompts. :assistant, the built-in assistant, asks before every change instead.', ['assistant' => $assistantName])],
        ['ri-shield-check-line', __('It acts as you, in one workspace'), __('It can do what you can do there, and nothing else. Revoke it at any time under Settings, Access Tokens.')],
        ['ri-coin-line', __('It spends no AI credits'), __('Credits are only for the built-in assistant. Relaticle does not charge for work done through :name.', ['name' => $name])],
        ['ri-tools-line', __('It works across your records'), __('Search, create, update and delete across companies, people, opportunities, tasks and notes.')],
    ];

    $costAnswer = $billingActive
        ? __('The connector adds no charge and spends no AI credits. It needs an active Relaticle Cloud workspace. Every new workspace starts with a :days-day Cloud Pro trial. After the trial, the workspace needs a Cloud Pro subscription to stay active.', ['days' => $trialDays])
        : __('The connector adds no charge and spends no AI credits. It needs a Relaticle Cloud workspace or a self-hosted install.');

    $faqs = [
        [
            __('Does Relaticle work with :name?', ['name' => $name]),
            __('Yes. :name connects to the Relaticle MCP server with OAuth. It can search, create, update and delete companies, people, opportunities, tasks and notes in the workspace you pick, custom fields included.', ['name' => $name]),
        ],
        [
            __('Can :name change my records without asking me?', ['name' => $name]),
            __('Through the :name connector, yes. Changes made through the connector apply directly inside the workspace you approved, with no approval card in Relaticle, so use the confirmation prompts :name gives you. The assistant built into Relaticle works differently: it proposes every change and waits for your approval.', ['name' => $name]),
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
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
    :ogImage="url('/images/open-graph-ai.jpg').'?v=1'"
>
    {{-- Hero --}}
    <section class="relative pt-32 pb-20 md:pt-40 md:pb-24 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-dynamic-component :component="$assistant->icon()" class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Relaticle for :name', ['name' => $name]) }}</span>
                </div>
            </div>

            <h1 class="text-balance font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('The CRM you run from :name', ['name' => $name]) }}
            </h1>

            <p class="mt-5 text-balance text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __('For founders who sell to a named list of companies. Ask about your pipeline, add the people you met, and log the next step from the chat you already have open.') }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-cloud::marketing.button href="{{ route('login') }}">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#connect">
                    {{ __('See how to connect') }}
                </x-cloud::marketing.button>
            </div>

            {{-- One exchange in the site's own bubble style, never a copy of the
                 assistant's interface. Stage names are the default pipeline's. --}}
            <figure
                class="mx-auto mt-14 max-w-2xl text-left"
                x-data="{ shown: false }"
                x-init="$nextTick(() => shown = true)"
            >
                <div class="overflow-hidden rounded-2xl border border-gray-200/80 bg-[var(--surface-block-bg)] shadow-[0_1px_2px_rgba(0,0,0,0.04),0_16px_40px_-16px_rgba(0,0,0,0.14)] dark:border-white/[0.08]">
                    <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/[0.06]">
                        <x-dynamic-component :component="$assistant->icon()" class="h-4 w-4 shrink-0 text-gray-900 dark:text-white"/>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $name }}</span>
                        <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ __('Relaticle connected') }}
                        </span>
                    </div>

                    <div class="space-y-4 px-4 py-5 sm:px-5">
                        <div
                            class="flex justify-end transition duration-500 ease-out motion-reduce:transition-none"
                            :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                        >
                            <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                                {{ $prompts[0][1] }}
                            </p>
                        </div>

                        <div
                            class="space-y-3 transition delay-300 duration-500 ease-out motion-reduce:transition-none motion-reduce:delay-0"
                            :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                        >
                            <p class="inline-flex items-center gap-1.5 rounded-md border border-gray-200/80 bg-gray-50 px-2 py-1 text-xs text-gray-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-gray-400">
                                <x-ri-plug-line class="h-3.5 w-3.5 shrink-0 text-primary dark:text-primary-400"/>
                                {{ __('Relaticle') }}
                                <span aria-hidden="true">·</span>
                                {{ __('Aggregate opportunities') }}
                                <x-ri-check-line class="h-3.5 w-3.5 shrink-0 text-emerald-500"/>
                            </p>

                            <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                                {{ __('You have 9 open opportunities worth $181,500. The largest share sits in proposals.') }}
                            </p>

                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-xs text-gray-400 dark:text-gray-500">
                                        <th scope="col" class="pb-2 text-left font-medium">{{ __('Stage') }}</th>
                                        <th scope="col" class="pb-2 text-right font-medium">{{ __('Opportunities') }}</th>
                                        <th scope="col" class="pb-2 pl-4 text-right font-medium">{{ __('Value') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                                    @foreach($exampleStages as [$stage, $count, $value])
                                        <tr>
                                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $stage }}</td>
                                            <td class="py-2 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $count }}</td>
                                            <td class="py-2 pl-4 text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $value }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <figcaption class="mt-3 text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ __('An example exchange. :name answers from the records in your own workspace.', ['name' => $name]) }}
                </figcaption>
            </figure>
        </div>
    </section>

    {{-- What you can ask --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('What you can ask :name', ['name' => $name]) }}
                </h2>
                <p class="mt-4 text-balance text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Plain requests, answered from your own records and written back to them.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($prompts as [$icon, $prompt, $outcome])
                    <div class="flex flex-col rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <h3 class="self-start rounded-2xl rounded-bl-md bg-gray-100 px-3.5 py-2.5 text-sm font-medium leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">
                            {{ $prompt }}
                        </h3>
                        <p class="mt-4 flex items-start gap-2.5 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            <x-dynamic-component :component="$icon" class="mt-0.5 h-4 w-4 shrink-0 text-primary dark:text-primary-400"/>
                            {{ $outcome }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Connect --}}
    <section id="connect" class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Connect :name in three steps', ['name' => $name]) }}
                </h2>
                <p class="mt-4 text-balance text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                    {{ __('The whole setup is a consent screen. No code and no API keys.') }}
                </p>
            </div>

            <ol class="space-y-4">
                @foreach($steps as [$stepTitle, $stepBody, $stepCode])
                    <li class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <div class="flex shrink-0 items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] font-display text-sm font-semibold text-primary dark:text-primary-400">
                            {{ $loop->iteration }}
                        </div>
                        <div>
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                                {{ $stepTitle }}
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ $stepBody }}
                            </p>
                            @if($stepCode)
                                <div class="mt-3 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                                    <code class="min-w-0 break-all rounded-md border border-gray-200/80 dark:border-white/[0.08] bg-gray-50 dark:bg-white/[0.04] px-2.5 py-1 font-mono text-xs text-gray-800 dark:text-gray-200">{{ $stepCode }}</code>
                                    <button
                                        type="button"
                                        @click="navigator.clipboard.writeText(@js($stepCode)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                        class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                    >
                                        <template x-if="! copied">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-ri-file-copy-line class="h-3.5 w-3.5"/>
                                                {{ __('Copy') }}
                                            </span>
                                        </template>
                                        <template x-if="copied">
                                            <span class="inline-flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
                                                <x-ri-check-line class="h-3.5 w-3.5"/>
                                                {{ __('Copied') }}
                                            </span>
                                        </template>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-3">
                @if($assistant === \Relaticle\Cloud\Marketing\Enums\ConnectableAssistant::ChatGPT)
                    <x-cloud::marketing.button variant="secondary" href="{{ $chatGptPluginUrl }}" :external="true">
                        {{ __('Open the ChatGPT plugin') }}
                    </x-cloud::marketing.button>
                @endif
                @if($docsActive)
                    <x-cloud::marketing.button variant="secondary" href="{{ route('help.show', ['category' => 'ai-assistant', 'slug' => 'connect-claude-or-chatgpt']) }}">
                        {{ __('Read the setup guide') }}
                    </x-cloud::marketing.button>
                @endif
            </div>
        </div>
    </section>

    {{-- Fit --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Built for founder-led sales') }}
                </h2>
                <p class="mt-4 text-balance text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('You know every company on your list and you run the deals yourself. The CRM should keep up without a sales ops person.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach($fit as [$icon, $cardTitle, $cardDesc])
                    <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] mb-4">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                            {{ $cardTitle }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            {{ $cardDesc }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- What to know --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-4xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('What to know before you connect') }}
                </h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($caveats as [$icon, $cardTitle, $cardDesc])
                    <div class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <div class="flex shrink-0 items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15]">
                            <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                        </div>
                        <div>
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                                {{ $cardTitle }}
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ $cardDesc }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-cloud::marketing.button variant="secondary" :icon="$sibling->icon()" href="{{ route($sibling->routeName()) }}">
                    {{ __('Using :name instead?', ['name' => $sibling->label()]) }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ route('ai') }}">
                    {{ __('Meet :name', ['name' => $assistantName]) }}
                </x-cloud::marketing.button>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-10">
                <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Questions about Relaticle and :name', ['name' => $name]) }}
                </h2>
            </div>

            <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="assistant-faq" />
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Put your company list where :name can reach it', ['name' => $name]) }}
            </h2>
            <p class="mt-4 text-balance text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ __('Free to start, no credit card required. Self-host it yourself whenever you want.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-cloud::marketing.button href="{{ route('login') }}">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ route('pricing') }}">
                    {{ __('See pricing') }}
                </x-cloud::marketing.button>
            </div>
        </div>
    </section>

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
