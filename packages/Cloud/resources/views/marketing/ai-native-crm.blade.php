@php
    $assistantName = (string) config('chat.assistant_name');
    $billingActive = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);
    $freeCredits = number_format(\App\Enums\Plan::Free->credits());
    $proCredits = number_format(\App\Enums\Plan::Pro->credits());
    $trialDays = \App\Models\Workspace::PRO_TRIAL_DAYS;

    $title = __('AI-native CRM: MCP, chat and self-hosting').' - Relaticle';
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

    $mcpDocsUrl = route('documentation.show', ['type' => 'mcp']);

    $checks = [
        [
            __('Can an outside agent work in it?'),
            __('Claude, ChatGPT, or an agent you wrote should get first-class access to records, not a screen-scraper.'),
            __('A first-party MCP server and a REST API. Agents connected over MCP write directly, under the same permissions as the user whose token they use.'),
            __('The MCP server guide'),
            $mcpDocsUrl,
        ],
        [
            __('Does the AI see your fields?'),
            __('Your custom fields, option lists, and relations are where the real data lives. An assistant that only sees the defaults is guessing.'),
            __('Every active custom field is readable and settable by the assistant and by agents, with option labels shown as text, not raw IDs.'),
            __('Custom fields over MCP'),
            $mcpDocsUrl.'#schema-resources',
        ],
        [
            __('Does the in-app assistant ask first?'),
            __('An assistant that can write to your pipeline needs a review step, or one bad prompt rewrites a quarter of deals.'),
            __(':name proposes every change as a card and waits. You approve or reject each record before anything is written.', ['name' => $assistantName]),
            __('How approvals work'),
            $documentationActive ? route('help.show', ['category' => 'ai-assistant', 'slug' => 'approve-what-the-assistant-changes']) : route('ai'),
        ],
        [
            __('Can it run where your data lives?'),
            __('If the only way to get the AI is the vendor cloud, the CRM is not yours in any way that matters.'),
            __('Self-host the whole thing with Docker Compose. Bring your own key for Claude or GPT, or point it at a local model with Ollama.'),
            __('Self-hosting'),
            route('selfHosted'),
        ],
        [
            __('Do you pay for AI per seat?'),
            __('Per-seat AI add-ons punish the teams that adopt it most.'),
            __('One flat price for unlimited users. AI is metered by what you use, in credits, not by how many people log in.'),
            __('Pricing'),
            route('pricing'),
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
            __('A CRM built so that both its own assistant and outside agents work on the data model itself, with permissions and review designed in from the start. Relaticle is built this way. Outside agents write through the MCP server as the user they act for, and the built-in assistant proposes before it writes.'),
        ],
    ];

    $paths = [
        [
            __('Connect what you already use'),
            __('Claude and ChatGPT reach your workspace through the built-in MCP server. The setup is a consent screen, with no code and no API keys.'),
            [
                [__('Relaticle for Claude'), route('assistants.claude'), 'ri-claude-fill'],
                [__('Relaticle for ChatGPT'), route('assistants.chatgpt'), 'ri-openai-fill'],
            ],
        ],
        [
            __('Meet the assistant inside'),
            __(':name opens beside every page, answers from your records, and proposes every change as a card you approve or reject.', ['name' => $assistantName]),
            [
                [__('Meet :name', ['name' => $assistantName]), route('ai'), null],
            ],
        ],
        [
            __('Run it where the data lives'),
            __('The self-hosted install runs the same open-source CRM as the cloud, with :name and the MCP server. Bring your own key, or run a local model with Ollama.', ['name' => $assistantName]),
            [
                [__('See self-hosting options'), route('selfHosted'), null],
                [__('Read the MCP server docs'), $mcpDocsUrl, null],
            ],
        ],
    ];

    $faqs = [
        [
            __('Is an AI-native CRM the same as an agentic CRM?'),
            __('No. Agentic describes what the AI does: it takes multi-step actions toward a goal. AI-native describes how the CRM is built: the data model, permissions, and review step are designed for AI to work in. A CRM can be agentic without being AI-native, and the difference shows the first time an agent writes something wrong.'),
        ],
        [
            __('Can an AI-native CRM run on my own server?'),
            __('Relaticle can. The self-hosted install runs the same open-source CRM as the cloud, with :name and the MCP server. You supply the model: your own API key for Claude or GPT, or a local model through Ollama, so no record has to leave your infrastructure.', ['name' => $assistantName]),
        ],
        [
            __('Does Relaticle work with Claude and ChatGPT?'),
            __('Yes. Connect either one to the MCP server and it can search, create, and update companies, people, opportunities, tasks, and notes in your workspace, including custom fields. The setup takes a few minutes and is documented for each assistant.'),
        ],
        [
            __('What does the AI cost?'),
            $costAnswer,
        ],
    ];

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
    :ogImage="url('/images/open-graph-ai.jpg').'?v=1'"
>
    <x-cloud::marketing.hero>
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('AI-native CRM') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('What makes a CRM') }} <span class="whitespace-nowrap">{{ __('AI-native?') }}</span>
            <span class="text-gray-500 dark:text-gray-400">{{ __('Five checks.') }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('In an AI-native CRM, the assistant and outside agents work on the data itself, not through a plugin on top.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ route('selfHosted') }}">
                    {{ __('Self-host it') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __(':days-day trial, no card, unlimited users.', ['days' => $trialDays]) }}</p>
        </x-slot:aside>

        @include('cloud::marketing.partials.ai-native-checklist', ['checks' => $checks])
    </x-cloud::marketing.hero>

    <x-cloud::marketing.frame id="definitions">
        <x-cloud::marketing.frame-header :label="__('Three words')" :lead="__('AI-powered, agentic, AI-native.')">
            {{ __('Vendors use them interchangeably. They are not the same thing.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($definitions as $index => [$term, $meaning])
                <div class="bg-surface p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $term }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $meaning }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="two-doors">
        <x-cloud::marketing.frame-header :label="__('Two doors')" :lead="__('Agents get a door.')">
            {{ __('And the door has a lock.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-2" data-two-doors>
            <div class="bg-surface flex flex-col">
                <div class="feat-card bg-tint bg-dots flex min-h-72 items-center justify-center px-6 py-10">
                    <x-cloud::marketing.window class="w-full max-w-sm">
                        <div class="border-rail flex items-center gap-2 border-b px-4 py-2.5">
                            <x-cloud::marketing.actor as="claude"/>
                            <span class="ml-auto inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ __('Relaticle connected') }}
                            </span>
                        </div>
                        <div class="space-y-3 px-4 py-4">
                            <div class="flex justify-end">
                                <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">{{ __('Move the Northwind renewal to Negotiation') }}</p>
                            </div>
                            <p class="border-rail inline-flex items-center gap-1.5 rounded-md border bg-tint px-2 py-1 text-xs text-gray-600 dark:text-gray-300">
                                <x-ri-plug-line class="size-3.5 shrink-0 text-primary dark:text-primary-400"/>
                                {{ __('Relaticle') }}
                                <span aria-hidden="true">·</span>
                                {{ __('Update opportunity') }}
                                <x-ri-check-line class="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"/>
                            </p>
                            <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ __('Done. Northwind renewal is now in Negotiation.') }}</p>
                        </div>
                    </x-cloud::marketing.window>
                </div>
                <div class="border-rail border-t p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('Through the MCP server') }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('Outside agents write directly, as you') }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __('Claude, ChatGPT, or an agent you wrote acts with the permissions of the user whose token it holds, in the one workspace you picked. Revoke it any time under Settings, Access Tokens.') }}</p>
                </div>
            </div>

            <div class="bg-surface flex flex-col">
                <div class="feat-card bg-tint bg-dots flex min-h-72 items-center justify-center px-6 py-10">
                    <x-cloud::marketing.window class="w-full max-w-sm">
                        <div class="border-rail flex items-center gap-2 border-b px-4 py-2.5">
                            <x-cloud::marketing.actor as="rela"/>
                        </div>
                        <div class="space-y-3 px-4 py-4">
                            <div class="flex justify-end">
                                <p class="max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-3.5 py-2.5 text-sm leading-relaxed text-gray-900 dark:bg-white/10 dark:text-gray-100">{{ __('Move the Northwind renewal to Negotiation') }}</p>
                            </div>
                            <div class="border-rail rounded-lg border">
                                <div class="border-rail flex items-center justify-between gap-3 border-b px-3 py-2">
                                    <span class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Northwind renewal') }}</span>
                                    <span class="type-label text-gray-500 dark:text-gray-400">{{ __('Update') }}</span>
                                </div>
                                <p class="flex items-center gap-2 px-3 py-2 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">{{ __('Stage') }}</span>
                                    <span class="text-gray-500 line-through dark:text-gray-400">{{ __('Proposal') }}</span>
                                    <x-ri-arrow-right-line class="size-3.5 text-gray-400" aria-hidden="true"/>
                                    <span class="font-medium text-gray-950 dark:text-white">{{ __('Negotiation') }}</span>
                                </p>
                                <div class="border-rail flex gap-2 border-t px-3 py-2">
                                    <span class="inline-flex h-7 items-center rounded-md bg-primary px-3 text-xs font-semibold text-white">{{ __('Approve') }}</span>
                                    <span class="border-rail inline-flex h-7 items-center rounded-md border px-3 text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('Reject') }}</span>
                                </div>
                            </div>
                        </div>
                    </x-cloud::marketing.window>
                </div>
                <div class="border-rail border-t p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ __('Through :name', ['name' => $assistantName]) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ __('The built-in assistant asks first') }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ __(':name proposes every change as a card and waits. You approve or reject each record before anything is written, and an unanswered proposal expires on its own.', ['name' => $assistantName]) }}</p>
                </div>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="paths">
        <x-cloud::marketing.frame-header :label="__('Where to start')" :lead="__('Three ways in.')">
            {{ __('Pick the one that matches how you work.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($paths as [$pathTitle, $pathDescription, $pathLinks])
                <div class="bg-surface flex flex-col gap-6 p-6 md:p-10">
                    <div>
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $pathTitle }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $pathDescription }}</p>
                    </div>
                    <div class="mt-auto flex flex-wrap gap-3">
                        @foreach($pathLinks as [$linkLabel, $linkHref, $linkIcon])
                            <x-cloud::marketing.button variant="secondary" size="sm" :href="$linkHref" :icon="$linkIcon">
                                {{ $linkLabel }}
                            </x-cloud::marketing.button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="ai-native-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Questions about AI-native CRMs, answered') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('contact') }}" class="{{ $linkClass }}">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="ai-native-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Run the five checks on Relaticle yourself.')" :continuation="__('Free to start, self-host whenever you want.')">
        <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Start for free') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('pricing') }}">
            {{ __('See pricing') }}
        </x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

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
</x-cloud::guest-layout>
