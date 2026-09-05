@php
    $assistantName = (string) config('chat.assistant_name');
    $mcpToolCount = \App\Support\CompetitorFacts::mcpToolCount();
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $freeCredits = number_format(\App\Enums\Plan::Free->credits());
    $proCredits = number_format(\App\Enums\Plan::Pro->credits());
    $trialDays = \App\Actions\Billing\StartProTrial::TRIAL_DAYS;

    $title = __('AI-Native CRM: What It Means, and One You Can Self-Host').' - Relaticle';
    $description = __(
        'What makes a CRM AI-native, five checks to run before you buy one, and how Relaticle passes each of them on your own server.'
    );

    $costAnswer = $billingActive
        ? __(
            'Relaticle Cloud meters AI by credits, never by seat. New workspaces get a :days-day Cloud Pro trial with a :credits-credit monthly allowance, and the paid plan keeps that allowance for unlimited users. A self-hosted install brings its own provider key or a local model and pays that provider directly.',
            ['days' => $trialDays, 'credits' => $proCredits]
        )
        : __(
            'Relaticle Cloud meters AI by credits, never by seat: every workspace gets a :credits-credit monthly allowance. A self-hosted install brings its own provider key or a local model and pays that provider directly.',
            ['credits' => $freeCredits]
        );

    $checks = [
        [
            'ri-plug-line',
            __('Can an outside agent work in it?'),
            __('Claude, ChatGPT, or an agent you wrote should get first-class access to records, not a screen-scraper.'),
            __('A first-party MCP server with :count tools and a REST API. Agents connected over MCP write directly, under the same permissions as the user whose token they use.', ['count' => $mcpToolCount]),
        ],
        [
            'ri-stack-line',
            __('Does the AI see your fields?'),
            __('Your custom fields, option lists, and relations are where the real data lives. An assistant that only sees the defaults is guessing.'),
            __('Every active custom field is readable and settable by the assistant and by agents, with option labels shown as text, not raw IDs.'),
        ],
        [
            'ri-shield-check-line',
            __('Does the in-app assistant ask first?'),
            __('An assistant that can write to your pipeline needs a review step, or one bad prompt rewrites a quarter of deals.'),
            __(':name proposes every change as a card and waits. You approve or reject each record before anything is written.', ['name' => $assistantName]),
        ],
        [
            'ri-server-line',
            __('Can it run where your data lives?'),
            __('If the only way to get the AI is the vendor cloud, the CRM is not yours in any way that matters.'),
            __('Self-host the whole thing with Docker Compose. Bring your own key for Claude or GPT, or point it at a local model with Ollama.'),
        ],
        [
            'ri-price-tag-3-line',
            __('Do you pay for AI per seat?'),
            __('Per-seat AI add-ons punish the teams that adopt it most.'),
            __('One flat price for unlimited users. AI is metered by what you use, in credits, not by how many people log in.'),
        ],
    ];

    $definitions = [
        [
            __('AI-powered CRM'),
            __('A classic CRM with AI features attached: summaries, lead scoring, email drafts. Useful, but the AI sits beside the data model rather than inside it.'),
        ],
        [
            __('Agentic CRM'),
            __('A CRM where agents take multi-step actions toward a goal: find the stale deals, draft the follow-ups, schedule the tasks. The question is who checks the work before it lands.'),
        ],
        [
            __('AI-native CRM'),
            __('A CRM built so that both its own assistant and outside agents work on the data model directly, with permissions and review designed in from the start. Relaticle is built this way, and its assistant is agentic within one rule: it asks before it writes.'),
        ],
    ];

    $faqs = [
        [
            __('Is an AI-native CRM the same as an agentic CRM?'),
            __('No. Agentic describes what the AI does: it takes multi-step actions toward a goal. AI-native describes how the CRM is built: the data model, permissions, and review step are designed for AI to work in. A CRM can be agentic without being AI-native, and the reverse.'),
        ],
        [
            __('Can an AI-native CRM run on my own server?'),
            __('Relaticle can. The self-hosted install is the same codebase as the cloud, including the assistant and the MCP server. You supply the model: your own API key for Claude or GPT, or a local model through Ollama, so no record has to leave your infrastructure.'),
        ],
        [
            __('Does Relaticle work with Claude and ChatGPT?'),
            __('Yes. Connect either one to the MCP server and it can search, create, and update companies, people, opportunities, tasks, and notes in your workspace, including custom fields. The setup takes a few minutes and is documented in the developer guide.'),
        ],
        [
            __('What does the AI cost?'),
            $costAnswer,
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
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-cpu-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('AI-native CRM') }}</span>
                </div>
            </div>

            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('The AI-native CRM you can run on your own server') }}
            </h1>

            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __('AI-native means the assistant and the agents are part of the CRM, not a plugin on top. Here is what to look for, and how Relaticle does each of it.') }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('login') }}">
                    {{ __('Start for free') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('selfHosted') }}">
                    {{ __('Self-host it') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- What AI-native means --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('What AI-native actually means') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Three things have to be true of the CRM itself, before any model is involved.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach([
                    ['ri-database-2-line', __('The AI reads the same records you do'), __('Companies, people, deals, notes, and every custom field are one data model that people and agents share. No export, no sidecar copy.')],
                    ['ri-door-open-line', __('Agents get a door, and the door has a lock'), __('A standard tool interface lets any agent work in the CRM, and every write passes through the same authorization a person would.')],
                    ['ri-cpu-line', __('You choose the model'), __('Cloud or local, today or next year. A CRM that only works with one vendor\'s AI has made the choice for you.')],
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

    {{-- Five checks --}}
    <section id="checks" class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-4xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Five checks before you call a CRM AI-native') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Run these against any CRM, including this one. The right-hand column is how Relaticle answers each.') }}
                </p>
            </div>

            <div class="space-y-4">
                @foreach($checks as [$icon, $question, $why, $answer])
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <div class="flex gap-4">
                            <div class="flex shrink-0 items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15]">
                                <x-dynamic-component :component="$icon" class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                            </div>
                            <div>
                                <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                                    {{ $question }}
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                                    {{ $why }}
                                </p>
                            </div>
                        </div>
                        <div class="md:border-l md:border-gray-200/80 md:dark:border-white/[0.06] md:pl-6">
                            <p class="uppercase tracking-wider text-[10px] font-medium text-gray-400 dark:text-gray-500 mb-1.5">{{ __('In Relaticle') }}</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                {{ $answer }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button variant="secondary" href="{{ route('documentation.show', ['type' => 'mcp']) }}">
                    {{ __('Read the MCP server docs') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('ai') }}">
                    {{ __('Meet :name', ['name' => $assistantName]) }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- Definitions --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('AI-powered, agentic, AI-native: which is which') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                    {{ __('Vendors use the three interchangeably. They are not the same thing.') }}
                </p>
            </div>

            <div class="space-y-4">
                @foreach($definitions as [$term, $meaning])
                    <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                            {{ $term }}
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            {{ $meaning }}
                        </p>
                    </div>
                @endforeach
            </div>
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
                {{ __('The same AI, on your own server') }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed max-w-xl mx-auto">
                {{ __('The self-hosted install is the same codebase as the cloud, assistant and MCP server included. Bring your own API key for Claude or GPT, or run a local model with Ollama, and keep every request on infrastructure you control.') }}
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
                    {{ __('Questions about AI-native CRMs, answered') }}
                </h2>
            </div>

            <x-marketing.faq-accordion :faqs="$faqs" id-prefix="ai-native-faq" />
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Run the five checks on Relaticle yourself') }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ __('Free to start, no credit card required. Self-host it yourself whenever you want.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('login') }}">
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
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('AI-native CRM'))->item(route('aiNativeCrm')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
