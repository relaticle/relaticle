@php
    $assistantName = (string) config('chat.assistant_name');

    $toolCapableCloudModels = collect(config('chat.models', []))
        ->filter(fn (array $model): bool => ($model['supports_tools'] ?? false) === true && ($model['self_hosted'] ?? false) === false);
    $freeCloudModels = $toolCapableCloudModels->where('min_plan', 'free')->pluck('label')->join(', ', ' and ');
    $paidCloudModels = $toolCapableCloudModels->where('min_plan', 'pro')->pluck('label')->join(', ', ' and ');

    $freeCredits = number_format(\App\Enums\Plan::Free->credits());
    $maxBatchSize = (int) config('chat.max_batch_size');
    // Rendered as a human duration so raising the config never leaks "1440 minutes" into the page.
    $pendingActionExpiry = \Carbon\CarbonInterval::minutes((int) config('chat.pending_action_expiry_minutes'))
        ->cascade()
        ->forHumans(short: false, parts: 1);
    $opportunityIcon = \Relaticle\Chat\Support\RecordChipRenderer::iconPath('opportunity');

    $title = __(':name: AI Assistant for Relaticle CRM', ['name' => $assistantName]).' - Relaticle';
    $description = __(
        ":name is Relaticle's built-in AI assistant. It finds, creates, and updates your records, and proposes every change for you to approve or reject first.",
        ['name' => $assistantName]
    );

    $faqs = [
        [
            __('What can :name change in my CRM?', ['name' => $assistantName]),
            __(
                'Anything you can change by hand: companies, people, opportunities, tasks, and notes, one record or a batch of up to :max. Every write still needs your approval first.',
                ['max' => $maxBatchSize]
            ),
        ],
        [
            __('Does :name invent data?', ['name' => $assistantName]),
            __(
                ':name works only on your records and Relaticle\'s own product documentation. When it does not know something, it says so, or links you to the page where you can do it yourself, instead of guessing.',
                ['name' => $assistantName]
            ),
        ],
        [
            __('Which models power :name?', ['name' => $assistantName]),
            __(
                ':freeModels on every plan. A paid plan additionally unlocks :paidModels, which cost more credits per reply. A self-hosted install supplies its own provider key, so you choose the model and pay the provider directly, or run a local one.',
                ['freeModels' => $freeCloudModels, 'paidModels' => $paidCloudModels]
            ),
        ],
        [
            __('Is :name included in the free plan?', ['name' => $assistantName]),
            __(
                'Yes. On Relaticle Cloud every workspace, free or paid, gets :name with a :credits-credit monthly allowance on the free-tier model, and upgrading raises the allowance and unlocks the higher-tier models. A self-hosted install ships with no provider configured, so you add your own key or point it at a local model, and you pay that provider directly.',
                ['name' => $assistantName, 'credits' => $freeCredits]
            ),
        ],
    ];
@endphp

<x-guest-layout
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

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-sparkling-2-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Meet :name', ['name' => $assistantName]) }}</span>
                </div>
            </div>

            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('The AI teammate inside your CRM') }}
            </h1>

            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __(':name does real CRM work: it finds, creates, and updates your records. You approve every change before it happens.', ['name' => $assistantName]) }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('register') }}">
                    {{ __('Start for free') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="#demo">
                    {{ __('See it work') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- Anatomy of a change: three stills of the real approval flow, revealed on
         scroll. Deliberately NOT the homepage hero mockup, which replays the same
         animation a visitor has already seen. Each step is a purpose-built
         fragment mirroring the real proposal card in
         packages/Chat/resources/views/livewire/chat/partials/_proposal-card.blade.php --}}
    <section id="demo" class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950 overflow-hidden">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Anatomy of a change') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('You ask. :name proposes. Nothing is written until you say so.', ['name' => $assistantName]) }}
                </p>
            </div>

            <ol
                x-data="{ shown: false }"
                x-init="
                    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        shown = true;
                    } else {
                        const observer = new IntersectionObserver((entries) => {
                            if (entries[0].isIntersecting) {
                                shown = true;
                                observer.disconnect();
                            }
                        }, { threshold: 0.25 });
                        observer.observe($el);
                    }
                "
                class="grid grid-cols-1 md:grid-cols-3 gap-5"
            >
                {{-- Step 1: the ask --}}
                <li class="flex flex-col transition duration-500 ease-out motion-reduce:transition-none"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">
                        {{ __('Step 1: you ask') }}
                    </p>
                    <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-4 flex-1">
                        <div class="rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] px-3.5 py-2.5">
                            <p class="text-sm text-gray-900 dark:text-gray-100 leading-relaxed">
                                {{ __('Mark the Northwind renewal as won and set the close date to today') }}
                            </p>
                        </div>
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('Plain language. No commands to memorize.') }}
                        </p>
                    </div>
                </li>

                {{-- Step 2: the proposal card --}}
                <li class="flex flex-col transition delay-150 duration-500 ease-out motion-reduce:transition-none motion-reduce:delay-0"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">
                        {{ __('Step 2: :name proposes', ['name' => $assistantName]) }}
                    </p>
                    <div class="overflow-hidden rounded-xl border border-[var(--surface-block-border)] bg-[var(--surface-block-bg)] flex-1">
                        <div class="flex min-w-0 items-center gap-2 px-4 py-2.5">
                            <span class="chat-chip min-w-0" data-proposal-record-chip data-record-type="opportunity">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $opportunityIcon }}"/>
                                </svg>
                                <span class="chat-chip-label">{{ __('Northwind renewal') }}</span>
                            </span>
                            <span class="shrink-0 text-[length:var(--text-micro)] font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">{{ __('Update') }}</span>
                        </div>

                        <dl class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-white/5 dark:border-white/5">
                            @foreach([
                                [__('Stage'), __('Negotiation'), __('Won')],
                                [__('Close date'), __('Not set'), __('Today')],
                            ] as [$field, $before, $after])
                                <div class="flex items-center gap-3 px-4 py-2.5 text-sm">
                                    <dt class="w-24 shrink-0 text-[length:var(--text-micro)] font-medium text-gray-400 sm:w-28 dark:text-gray-500">{{ $field }}</dt>
                                    <dd class="flex min-w-0 items-center gap-1.5">
                                        <span class="text-gray-400 dark:text-gray-500 line-through truncate">{{ $before }}</span>
                                        <x-ri-arrow-right-line class="h-3 w-3 shrink-0 text-gray-300 dark:text-gray-600"/>
                                        <span class="font-medium text-gray-900 dark:text-white truncate">{{ $after }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </li>

                {{-- Step 3: the approval --}}
                <li class="flex flex-col transition delay-300 duration-500 ease-out motion-reduce:transition-none motion-reduce:delay-0"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">
                        {{ __('Step 3: you decide') }}
                    </p>
                    <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-4 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-7 items-center rounded-md bg-gray-900 px-2.5 text-xs font-medium text-white shadow-sm dark:bg-white dark:text-gray-900">
                                {{ __('Approve') }}
                            </span>
                            <span class="inline-flex h-7 items-center rounded-md border border-gray-200 bg-white px-2.5 text-xs font-medium text-gray-700 shadow-sm dark:border-white/[0.08] dark:bg-white/5 dark:text-gray-300">
                                {{ __('Reject') }}
                            </span>
                        </div>

                        <div class="mt-3 flex items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 rounded-md bg-green-50 px-1.5 py-0.5 font-medium text-green-700 dark:bg-green-400/10 dark:text-green-400">
                                <x-ri-check-line class="h-3 w-3"/>
                                {{ __('Updated') }}
                            </span>
                            <span class="text-gray-400 dark:text-gray-500 truncate">{{ __('Northwind renewal') }}</span>
                        </div>

                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('Reject and nothing is written. The proposal expires on its own after :duration.', ['duration' => $pendingActionExpiry]) }}
                        </p>
                    </div>
                </li>
            </ol>
        </div>
    </section>

    {{-- Capabilities --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('What :name actually does', ['name' => $assistantName]) }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('No canned demos. Every capability below runs against your real workspace.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach([
                    ['ri-edit-2-line', __('Creates and updates any record'), __('Companies, people, opportunities, tasks, and notes. Ask for a field to change and it proposes the edit.')],
                    ['ri-checkbox-multiple-line', __('Proposes many records at once'), __('Ask for a batch of up to :max records. You review them one at a time and keep the ones you want.', ['max' => $maxBatchSize])],
                    ['ri-stack-line', __('Knows your custom fields'), __('Every active custom field your team has defined works out of the box, with option labels shown as text, not raw IDs.')],
                    ['ri-book-open-line', __('Answers questions from the docs'), __('Ask how something works and it searches Relaticle\'s own documentation for the answer, with a link to the source.')],
                    ['ri-search-line', __('Finds and summarizes your data'), __('Look up a record, list your team, or get a rollup of your pipeline without opening a single page.')],
                    ['ri-guide-line', __('Guides you to the right page'), __('For the few things it cannot do itself, like importing a file or changing a field type, it links you straight to where you can.')],
                ] as [$icon, $cardTitle, $cardDesc])
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

    {{-- Control / trust --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('You stay in control') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                    {{ __('An assistant that can write to your CRM only earns trust one way: by never writing without asking first.') }}
                </p>
            </div>

            <div class="space-y-4">
                <div class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] shrink-0">
                        <x-ri-shield-check-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                    </div>
                    <div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                            {{ __('Nothing writes without your approval') }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            {{ __('Every proposed create, update, or delete renders as a card showing the old value and the new one. You approve or reject it; nothing saves until you do. An unanswered proposal expires after :duration.', ['duration' => $pendingActionExpiry]) }}
                        </p>
                    </div>
                </div>

                <div class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] shrink-0">
                        <x-ri-checkbox-multiple-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                    </div>
                    <div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                            {{ __('Batches are reviewed record by record') }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            {{ __('A batch of up to :max records is stepped through one at a time. Keep the ones you want, reject the rest, and nothing you skipped is written.', ['max' => $maxBatchSize]) }}
                        </p>
                    </div>
                </div>

                <div class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] shrink-0">
                        <x-ri-hand-coin-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                    </div>
                    <div>
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                            {{ __('Transparent credits, your choice of model') }}
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                            {{ __(':freeModels is free on every plan. Switch to :paidModels on a paid plan when a harder question calls for it.', ['freeModels' => $freeCloudModels, 'paidModels' => $paidCloudModels]) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- MCP --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-plug-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('MCP server') }}</span>
                </div>
            </div>

            <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Use Relaticle from Claude or ChatGPT') }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                {{ __('Relaticle ships a built-in MCP server, so your own AI client can read and write your CRM directly. It works with Claude, ChatGPT, and any other MCP-compatible client. MCP calls run against the same records, and because you authorized the client yourself, they apply straight away rather than waiting for an in-app approval.') }}
            </p>

            @feature(App\Features\Documentation::class)
                <div class="mt-8">
                    <x-marketing.button variant="secondary" href="{{ route('documentation.index') }}">
                        {{ __('Read the developer docs') }}
                    </x-marketing.button>
                </div>
            @endfeature
        </div>
    </section>

    {{-- Self-hosted AI --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-server-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Self-hosted AI') }}</span>
                </div>
            </div>

            <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Run the assistant on your own server') }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                {{ __('Self-hosting Relaticle does not mean giving up the assistant. On your own server, bring your own API key for Claude or GPT, or point it at a local model with Ollama and keep every request on your own infrastructure.') }}
            </p>

            <div class="mt-8">
                <x-marketing.button variant="secondary" href="{{ route('selfHosted') }}">
                    {{ __('See self-hosting options') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-10">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Questions about :name, answered', ['name' => $assistantName]) }}
                </h2>
            </div>

            <x-marketing.faq-accordion :faqs="$faqs" id-prefix="ai-faq" />
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Try :name in your own workspace', ['name' => $assistantName]) }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ __('Free to start, no credit card required. Self-host it yourself whenever you want.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('register') }}">
                    {{ __('Start for free') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('pricing') }}">
                    {{ __('See pricing') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($page) => $page
                ->name($title)
                ->description($description)
                ->url(url()->current()))
            ->fAQPage(fn ($page) => $page
                ->mainEntity(collect($faqs)->map(fn (array $faq) => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name($assistantName)->item(route('ai')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
