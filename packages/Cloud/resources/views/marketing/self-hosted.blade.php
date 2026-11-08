@php
    $assistantName = (string) config('chat.assistant_name');
    $documentationActive = \Laravel\Pennant\Feature::active(\App\Features\Documentation::class);

    $title = __('Self-hosted CRM: Run it on your server').' - Relaticle';
    $description = __('Deploy Relaticle, the open-source AGPL-3.0 CRM, on your own server with two Docker commands. Unlimited users, no per-seat pricing, and your data stays yours.');

    $stats = array_values(array_filter([
        $githubStars > 0 ? [__(':count+', ['count' => $formattedGithubStars]), __('GitHub stars'), 'https://github.com/relaticle/relaticle'] : null,
        $formattedDockerPulls !== null ? [$formattedDockerPulls, __('Docker pulls'), 'https://hub.docker.com/r/manukminasyan/relaticle'] : null,
        $formattedDiscordMembers !== null ? [$formattedDiscordMembers, __('Discord members'), route('discord')] : null,
    ]));

    $reasons = [
        [
            __('Your data stays on your server'),
            __('Every company, person, opportunity, task, and uploaded file lives on the PostgreSQL, Redis, and storage volumes compose.yml defines. Nothing is sent to Relaticle.'),
        ],
        [
            __('Source you can read and change'),
            __('Everything a self-hosted install runs, including :name and the MCP server, is on GitHub under AGPL-3.0. Audit it, modify it, and redistribute it under the same license.', ['name' => $assistantName]),
        ],
        [
            __('One flat cost: your server'),
            __('Unlimited users and unlimited records on every install. No per-seat price, no license fee, and no user-count paywall to hit.'),
        ],
    ];

    $containers = [
        ['app', 'ghcr.io/relaticle/relaticle', __('Serves the app, the API and the MCP server, and runs migrations on startup.')],
        ['horizon', 'ghcr.io/relaticle/relaticle', __('The queue worker: imports, exports, email, and :name\'s replies.', ['name' => $assistantName])],
        ['scheduler', 'ghcr.io/relaticle/relaticle', __('Runs the recurring jobs: cleanup and notifications.')],
        ['reverb', 'ghcr.io/relaticle/relaticle', __('The websocket server that streams :name\'s replies to the browser.', ['name' => $assistantName])],
        ['postgres', 'postgres:17-alpine', __('PostgreSQL 17, on a volume you back up.')],
        ['redis', 'redis:7-alpine', __('Cache, sessions, and the queue, with append-only persistence.')],
    ];

    $providerLines = [
        ['comment', __('Cloud models, billed to your own account')],
        ['env', 'ANTHROPIC_API_KEY', 'sk-ant-…'],
        ['env', 'OPENAI_API_KEY', 'sk-…'],
        ['blank'],
        ['comment', __('A local model through Ollama')],
        ['env', 'OLLAMA_BASE_URL', 'http://host.docker.internal:11434'],
        ['env', 'OLLAMA_MODEL', 'qwen3:14b'],
        ['blank'],
        ['comment', __('Or any OpenAI-compatible endpoint, such as vLLM')],
        ['env', 'SELF_HOSTED_AI_URL', 'http://host.docker.internal:8000/v1'],
        ['env', 'SELF_HOSTED_AI_MODELS', 'qwen3:14b,llama3.1:70b'],
    ];

    $comparison = [
        [__('Infrastructure'), __('Your own server, under your own operational control.'), __('Relaticle-managed infrastructure.')],
        [__('Updates and backups'), __('You pull new images and back up PostgreSQL yourself. The guide has the pg_dump commands.'), __('Handled by Relaticle, with no maintenance window to schedule.')],
        [__('AI models'), __('Bring your own provider key, or run a local model with Ollama.'), __('Cloud-hosted models ready immediately, with no provider key required.')],
        [__('Getting help'), __('Community support on Discord and GitHub issues.'), __('Email support.')],
    ];

    $faqs = [
        [
            __('What license is Relaticle released under?'),
            __('AGPL-3.0. Everything a self-hosted install runs, including :name, the built-in AI assistant, and the MCP server, is on GitHub under that license. Read it, audit it, modify it, and redistribute it under the same terms.', ['name' => $assistantName]),
        ],
        [
            __('How do I update a self-hosted install?'),
            __('Pull the new images and restart the stack: :pull then :up. Database migrations run automatically on startup, so check the release notes for breaking changes before you upgrade.', [
                'pull' => 'docker compose pull',
                'up' => 'docker compose up -d',
            ]),
        ],
        [
            __('What does :name need to work on a self-hosted install?', ['name' => $assistantName]),
            __('A provider it can call. Set an API key for Claude or GPT, or point it at Ollama or any OpenAI-compatible endpoint. With neither configured, the assistant has no model to answer with.'),
        ],
        [
            __('Where does my data live?'),
            __('On the PostgreSQL, Redis, and storage volumes defined in compose.yml, all running on your own server. Nothing is sent to Relaticle-operated infrastructure unless you choose the hosted Cloud instead.'),
        ],
        [
            __('What server do I need?'),
            __('The published minimum is :ramMin of RAM and :cpuMin, with :ramRec recommended for real workloads. Docker 20.10+ and Docker Compose v2 are required.', [
                'ramMin' => '2 GB',
                'cpuMin' => '1 core',
                'ramRec' => '4 GB',
            ]),
        ],
        [
            __('Can I move between self-hosted and Cloud later?'),
            __('Yes. Both run the same CRM against the same schema, and every record type has a built-in CSV export, so moving between them is an export and re-import, not a proprietary migration.'),
        ],
    ];

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
    :ogImage="url('/images/open-graph-self-hosted.jpg').'?v=1'"
>
    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Self-hosted') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('Your CRM, on your server.') }}
            <span class="text-gray-500 dark:text-gray-400"><span class="whitespace-nowrap">AGPL-3.0,</span> {{ __(':name included.', ['name' => $assistantName]) }}</span>
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('Download the compose file, add two secrets, start it. Unlimited users, no per-seat cost, and an install below that is ready to paste.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                @if($documentationActive)
                    <x-cloud::marketing.button href="{{ route('documentation.show', ['type' => 'self-hosting']) }}">
                        {{ __('Read the guide') }}
                    </x-cloud::marketing.button>
                    <x-cloud::marketing.button variant="secondary" href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
                        {{ __('View on GitHub') }}
                    </x-cloud::marketing.button>
                @else
                    <x-cloud::marketing.button href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
                        {{ __('View on GitHub') }}
                    </x-cloud::marketing.button>
                @endif
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('2 GB of RAM to start. Docker 20.10+ with Compose v2.') }}</p>
        </x-slot:aside>

        <x-slot:visual>
            <x-cloud::marketing.stage light="corners" class="bg-tint rounded-3xl">
                @include('cloud::marketing.partials.self-hosted-install')
            </x-cloud::marketing.stage>
        </x-slot:visual>
    </x-cloud::marketing.hero>

    @if($stats !== [])
        <x-cloud::marketing.frame>
            <div class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-[repeat(auto-fit,minmax(0,1fr))]">
                @foreach($stats as [$value, $label, $href])
                    <x-cloud::marketing.stat :value="$value" :label="$label" :href="$href"/>
                @endforeach
            </div>
        </x-cloud::marketing.frame>
    @endif

    <x-cloud::marketing.frame>
        <x-cloud::marketing.frame-header :label="__('Open source')" :lead="__('Everything, under one license.')">
            {{ __('Not a community edition.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-3">
            @foreach($reasons as $index => [$reasonTitle, $reasonDescription])
                <div class="bg-surface p-6 md:p-10">
                    <x-cloud::marketing.eyebrow class="mb-4">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $reasonTitle }}</h3>
                    <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $reasonDescription }}</p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="stack">
        <x-cloud::marketing.frame-header :label="__('The stack')" :lead="__('Six containers from one command.')">
            {{ __('Each one named in the published compose file.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid grid-cols-1 gap-px sm:grid-cols-2 lg:grid-cols-3">
            @foreach($containers as [$container, $image, $purpose])
                <div class="bg-surface flex flex-col gap-3 p-6 md:p-8">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="font-mono text-sm font-semibold text-gray-950 dark:text-white">{{ $container }}</h3>
                        <span class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">{{ $image }}</span>
                    </div>
                    <p class="type-prose text-gray-500 dark:text-gray-400">{{ $purpose }}</p>
                </div>
            @endforeach
        </div>

        <div class="bg-surface border-rail border-t px-6 py-5 md:px-8">
            <p class="type-prose text-gray-700 dark:text-gray-300">
                {{ __('The guide also covers Dokploy, Coolify, Laravel Cloud, reverse proxies, backups, and a Docker-free manual install.') }}
                @if($documentationActive)
                    <a href="{{ route('documentation.show', ['type' => 'self-hosting']) }}" class="{{ $linkClass }}">{{ __('Read the full self-hosting guide') }}</a>
                @endif
            </p>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="ai">
        <x-cloud::marketing.frame-header :label="__(':name, self-hosted', ['name' => $assistantName])" :lead="__('Your keys, your models.')">
            {{ __('Nothing answers until you configure a provider.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid gap-px">
            <x-cloud::marketing.showcase
                :label="__('Providers')"
                :lead="__(':name ships in the image.', ['name' => $assistantName])"
                :facts="[
                    __('Relaticle does not meter AI credits on a self-hosted install.'),
                    __('Claude or GPT with your own key, at your own usage rates'),
                    __('Ollama, vLLM, or any OpenAI-compatible endpoint for a local model'),
                ]"
                :link-label="__('Choosing a model that calls tools')"
                :link-href="$documentationActive ? route('documentation.show', ['type' => 'self-hosting']).'#ai-assistant' : null"
            >
                {{ __('You decide what it calls.') }}

                <x-slot:visual>
                    <div class="flex h-full items-center justify-center px-4 py-10 sm:px-8">
                        <x-cloud::marketing.window class="w-full max-w-xl">
                            <div class="border-rail flex items-center gap-3 border-b px-4 py-2.5">
                                <span class="font-mono text-xs text-gray-500 dark:text-gray-400">.env</span>
                            </div>
                            <div class="px-4 py-4 font-mono text-[12px] leading-6 sm:text-[13px]">
                                @foreach($providerLines as $providerLine)
                                    @if($providerLine[0] === 'blank')
                                        <div class="h-3" aria-hidden="true"></div>
                                    @elseif($providerLine[0] === 'comment')
                                        <p class="text-gray-500 dark:text-gray-400"><span>#</span> {{ $providerLine[1] }}</p>
                                    @else
                                        <p class="whitespace-pre-wrap break-all"><span class="text-gray-950 dark:text-white">{{ $providerLine[1] }}</span><span class="text-gray-400 dark:text-gray-500">=</span><span class="text-primary dark:text-primary-400">{{ $providerLine[2] }}</span></p>
                                    @endif
                                @endforeach
                            </div>
                        </x-cloud::marketing.window>
                    </div>
                </x-slot:visual>
            </x-cloud::marketing.showcase>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="cloud-or-self-hosted">
        <x-cloud::marketing.frame-header :label="__('Self-hosted or Cloud')" :lead="__('Same CRM, two operators.')">
            {{ __('Who runs the server, and who provisions the models.') }}
        </x-cloud::marketing.frame-header>

        <div class="bg-rail grid gap-px">
            <div class="bg-rail hidden gap-px md:grid md:grid-cols-12" aria-hidden="true">
                <span class="bg-surface md:col-span-3"></span>
                <span class="bg-surface type-label px-8 py-4 text-gray-500 md:col-span-5 dark:text-gray-400">{{ __('Self-hosted') }}</span>
                <span class="bg-surface type-label px-8 py-4 text-gray-500 md:col-span-4 dark:text-gray-400">{{ __('Cloud') }}</span>
            </div>

            @foreach($comparison as [$rowLabel, $selfHostedValue, $hostedValue])
                <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-12">
                    <p class="bg-surface px-6 py-5 text-sm font-medium text-gray-950 md:col-span-3 md:px-10 dark:text-white">{{ $rowLabel }}</p>
                    <p class="bg-surface px-6 py-5 md:col-span-5 md:px-8">
                        <span class="type-label mb-1 block text-gray-500 md:sr-only dark:text-gray-400">{{ __('Self-hosted') }}:</span>
                        <span class="type-prose text-gray-700 dark:text-gray-300">{{ $selfHostedValue }}</span>
                    </p>
                    <p class="bg-surface px-6 py-5 md:col-span-4 md:px-8">
                        <span class="type-label mb-1 block text-gray-500 md:sr-only dark:text-gray-400">{{ __('Cloud') }}:</span>
                        <span class="type-prose text-gray-700 dark:text-gray-300">{{ $hostedValue }}</span>
                    </p>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="self-hosted-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Self-hosting questions, answered') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('discord') }}" class="{{ $linkClass }}">{{ __('Ask on Discord') }}</a>
                        {{ __('or') }}
                        <a href="https://github.com/relaticle/relaticle/issues" target="_blank" rel="noopener noreferrer" class="{{ $linkClass }}">{{ __('open an issue') }}</a>.
                        @if($documentationActive)
                            <a href="{{ route('documentation.show', ['type' => 'contributing']) }}" class="{{ $linkClass }}">{{ __('The contributing guide') }}</a>
                            {{ __('covers local setup.') }}
                        @endif
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="self-hosted-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Run it your way.')" :continuation="__('Your server, or ours.')">
        <x-cloud::marketing.button href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
            {{ __('Deploy self-hosted') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="footer">
            {{ __('Try Relaticle Cloud') }}
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
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Self-hosted'))->item(route('selfHosted')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-cloud::guest-layout>
