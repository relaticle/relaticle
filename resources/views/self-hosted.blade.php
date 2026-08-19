@php
    $assistantName = (string) config('chat.assistant_name');

    $title = __('Self-Hosted CRM: Deploy Relaticle on Your Own Server').' - Relaticle';
    $description = __('Deploy Relaticle, the open-source AGPL-3.0 CRM, on your own server with two Docker commands. Unlimited users, no per-seat pricing, and your data never leaves your infrastructure.');

    $quickStartLines = [
        ['type' => 'comment', 'text' => __('Download the compose file')],
        ['type' => 'command', 'text' => 'curl -o compose.yml https://raw.githubusercontent.com/relaticle/relaticle/main/compose.yml'],
        ['type' => 'blank'],
        ['type' => 'comment', 'text' => __('Add the two required secrets')],
        ['type' => 'command', 'text' => 'cat > .env << EOF'],
        ['type' => 'command', 'text' => 'APP_KEY=base64:$(openssl rand -base64 32)', 'continuation' => true],
        ['type' => 'command', 'text' => 'DB_PASSWORD=$(openssl rand -hex 24)', 'continuation' => true],
        ['type' => 'command', 'text' => 'EOF', 'continuation' => true],
        ['type' => 'blank'],
        ['type' => 'comment', 'text' => __('Start Relaticle')],
        ['type' => 'command', 'text' => 'docker compose up -d'],
    ];

    // What the copy button puts on the clipboard: commands only, no prompt glyphs
    // and no comment lines, so a paste into a real shell runs as-is.
    $quickStartClipboard = collect($quickStartLines)
        ->filter(fn (array $line): bool => ($line['type'] ?? null) === 'command')
        ->pluck('text')
        ->implode("\n");

    $faqs = [
        [
            __('What license is Relaticle released under?'),
            __('AGPL-3.0. The full application, the CRM, the AI assistant, and the MCP server, is on GitHub under that license: read it, audit it, modify it, and redeploy your own fork.'),
        ],
        [
            __('How do I update a self-hosted install?'),
            __('Pull the new images and restart the stack: :pull then :up. Database migrations run automatically on startup, so check the release notes for breaking changes before you upgrade.', [
                'pull' => 'docker compose pull',
                'up' => 'docker compose up -d',
            ]),
        ],
        [
            __('What does the AI assistant need to work on a self-hosted install?'),
            __('A provider it can call. Set an API key for Claude or GPT, or point it at a local Ollama server. With neither configured, the assistant has no model to answer with.'),
        ],
        [
            __('Where does my data live?'),
            __('On the PostgreSQL, Redis, and storage volumes defined in compose.yml, all running on your own server. Nothing is sent to Relaticle-operated infrastructure unless you choose the hosted Cloud plan instead.'),
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
            __('Yes. Both run the identical open-source codebase against the same schema, and every record type has a built-in CSV export, so moving between them is an export and re-import, not a proprietary migration.'),
        ],
    ];
@endphp

<x-guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
>
    {{-- Hero --}}
    <section class="relative pt-32 pb-20 md:pt-40 md:pb-24 bg-white dark:bg-gray-950 overflow-hidden">
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

        <div class="relative max-w-3xl mx-auto px-6 lg:px-8 text-center">

            {{-- Badge --}}
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-server-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Self-hosted') }}</span>
                </div>
            </div>

            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('Your CRM. Your server. Your data.') }}
            </h1>

            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __('Relaticle is open source under the AGPL-3.0 license. Run it on your own hardware with unlimited users and no per-seat cost, forever.') }}
            </p>
        </div>

        {{-- Terminal: a purpose-built command block, not the homepage hero mockup. The two
             lines the tests and the founder's brief require are kept verbatim from
             packages/Documentation/resources/content/docs/guides/self-hosting.md:19,39. The
             APP_KEY/DB_PASSWORD lines in between are added because compose.yml hard-requires
             both (${APP_KEY:?...}, ${DB_PASSWORD:?...}), so the two headline commands alone
             would fail if someone actually ran them. --}}
        <div class="relative mt-12 max-w-2xl mx-auto px-6 lg:px-8">
            <div class="rounded-2xl border border-black/[0.06] dark:border-white/[0.08] bg-gray-950 shadow-xl shadow-gray-950/10 overflow-hidden text-left">
                <div class="flex items-center gap-1.5 border-b border-white/[0.06] px-4 py-3">
                    <span class="h-2.5 w-2.5 rounded-full bg-red-500/70"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-yellow-500/70"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-green-500/70"></span>
                    <span class="ml-3 font-mono text-xs text-gray-500">{{ __('Terminal') }}</span>

                    <button
                        type="button"
                        x-data="{ copied: false }"
                        @click="navigator.clipboard.writeText(@js($quickStartClipboard)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="ml-auto inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-gray-400 hover:text-gray-100 transition-colors cursor-pointer"
                    >
                        <template x-if="! copied">
                            <span class="inline-flex items-center gap-1.5">
                                <x-ri-file-copy-line class="h-3.5 w-3.5"/>
                                {{ __('Copy') }}
                            </span>
                        </template>
                        <template x-if="copied">
                            <span class="inline-flex items-center gap-1.5 text-emerald-400">
                                <x-ri-check-line class="h-3.5 w-3.5"/>
                                {{ __('Copied') }}
                            </span>
                        </template>
                    </button>
                </div>
                <div class="px-5 py-5 font-mono text-[12px] sm:text-[13px] leading-relaxed">
                    @foreach($quickStartLines as $line)
                        @if(($line['type'] ?? null) === 'blank')
                            <div class="h-3" aria-hidden="true"></div>
                        @elseif(($line['type'] ?? null) === 'comment')
                            {{-- The `#` stays selectable, unlike the `$`/`>` prompts below: it is
                                 what keeps the line a shell comment once it is copied. --}}
                            <p class="text-gray-500">
                                <span>#</span> {{ $line['text'] }}
                            </p>
                        @else
                            {{-- Prompt glyphs carry `select-none` so a select-and-copy of the
                                 block yields runnable shell input, not stray `$`/`>` lines. --}}
                            <p class="flex gap-2 whitespace-pre-wrap break-all text-gray-100">
                                <span class="shrink-0 select-none {{ ($line['continuation'] ?? false) ? 'text-gray-600' : 'text-emerald-400' }}" aria-hidden="true">{{ ($line['continuation'] ?? false) ? '>' : '$' }}</span>
                                <span>{{ $line['text'] }}</span>
                            </p>
                        @endif
                    @endforeach
                </div>
            </div>
            <p class="mt-4 text-center text-xs text-gray-400 dark:text-gray-500">
                {{ __('Requires Docker 20.10+ and Docker Compose v2. Environment variables, reverse proxy configs, and a manual (non-Docker) install are covered in the guide below.') }}
            </p>
        </div>
    </section>

    {{-- Why self-host --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Why run it yourself') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('The same reasons people self-host any serious open-source tool.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach([
                    ['ri-database-2-line', __('Your data stays yours'), __('Every company, contact, opportunity, task, and uploaded file lives on infrastructure you control. Nothing is copied anywhere unless you choose to.')],
                    ['ri-scales-line', __('Source you can read and change'), __('Relaticle is licensed AGPL-3.0. The full application is on GitHub, not a stripped-down edition, so you can audit it, modify it, and redeploy your fork.')],
                    ['ri-hard-drive-line', __('One flat cost: your server'), __('Unlimited users and unlimited records on every self-hosted install. There is no per-seat pricing and no user-count paywall to hit.')],
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

    {{-- Quick start --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-14">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Three steps to a running CRM') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Distilled from the full guide, which also covers Dokploy, Coolify, Traefik, and a Docker-free manual install.') }}
                </p>
            </div>

            <ol class="space-y-4">
                @foreach([
                    [__('Get the compose file'), __('Download the published compose.yml and set the two variables it requires: :appKey and :dbPassword.', ['appKey' => 'APP_KEY', 'dbPassword' => 'DB_PASSWORD'])],
                    [__('Start the stack'), __('Run :command and Docker pulls five containers: the app, a queue worker, a scheduler, PostgreSQL, and Redis. Database migrations run automatically on startup.', ['command' => 'docker compose up -d'])],
                    [__('Create your admin account'), __('Run :command, choose the app panel, and sign in with the account you just created.', ['command' => 'docker compose exec app php artisan make:filament-user'])],
                ] as $index => [$stepTitle, $stepDesc])
                    <li class="flex gap-4 rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/[0.08] dark:bg-primary/[0.15] font-display text-sm font-semibold text-primary dark:text-primary-400">
                            {{ $index + 1 }}
                        </div>
                        <div>
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                                {{ $stepTitle }}
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                                {{ $stepDesc }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>

            @feature(App\Features\Documentation::class)
                <div class="mt-8 text-center">
                    <x-marketing.button variant="secondary" href="{{ route('documentation.show', ['type' => 'self-hosting']) }}">
                        {{ __('Read the full self-hosting guide') }}
                    </x-marketing.button>
                </div>
            @endfeature
        </div>
    </section>

    {{-- AI on your server --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-12">
                <div class="flex justify-center mb-6">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                        <x-ri-key-2-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                        <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('AI, self-hosted') }}</span>
                    </div>
                </div>

                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Your keys, your models') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __(':name is part of the self-hosted image, but it ships with no provider configured. You decide what it calls, if anything.', ['name' => $assistantName]) }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] mb-4">
                        <x-ri-key-2-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                    </div>
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                        {{ __('Bring your own provider key') }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('Set :anthropicKey or :openaiKey and the assistant calls Claude or GPT with your own account, at your own usage rates.', ['anthropicKey' => 'ANTHROPIC_API_KEY', 'openaiKey' => 'OPENAI_API_KEY']) }}
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6">
                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15] mb-4">
                        <x-ri-cpu-line class="w-4.5 h-4.5 text-primary dark:text-primary-400"/>
                    </div>
                    <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white mb-1.5">
                        {{ __('Run it locally with Ollama') }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ __('Point :ollamaUrl at your Ollama server and set :ollamaModel to the tag you want in the picker. From inside the containers, that usually means :dockerHost, not localhost.', ['ollamaUrl' => 'OLLAMA_BASE_URL', 'ollamaModel' => 'OLLAMA_MODEL', 'dockerHost' => 'http://host.docker.internal:11434']) }}
                    </p>
                </div>
            </div>

            <p class="mt-8 text-sm text-gray-500 dark:text-gray-400 leading-relaxed text-center max-w-xl mx-auto">
                {{ __('Either way, the model catalog in a hosted Relaticle Cloud workspace is provisioned by Relaticle. A self-hosted install starts with none of that: nothing answers until you configure a provider yourself.') }}
            </p>
        </div>
    </section>

    {{-- Cloud vs self-hosted --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-12">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Self-hosted or Cloud: how to choose') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('Both run the identical open-source Relaticle codebase. The real differences are who operates the server and who provisions the AI models.') }}
                </p>
            </div>

            {{--
                List layout, not a <table>, so the page still converts cleanly to markdown for
                the ProvideMarkdownResponse middleware this route runs under (same pattern as
                the comparison list in resources/views/pricing.blade.php).
            --}}
            <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-200/80 bg-white dark:divide-white/[0.04] dark:border-white/[0.06] dark:bg-white/[0.02]">
                @foreach([
                    [__('Infrastructure'), __('Your own server, under your own operational control'), __('Relaticle-managed infrastructure')],
                    [__('Updates and backups'), __('You pull new images and back up PostgreSQL yourself (the guide has the pg_dump commands)'), __('Handled by Relaticle, no maintenance window to schedule')],
                    [__('AI models'), __('Bring your own provider key, or run a local model with Ollama'), __('Cloud-hosted models ready immediately, no provider key required')],
                    [__('Getting help'), __('Community support on Discord and GitHub issues'), __('Email support')],
                ] as [$rowLabel, $selfHostedValue, $hostedValue])
                    <li class="px-4 py-4 sm:px-6">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $rowLabel }}</p>
                        <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-400">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Self-Hosted') }}:</span> {{ $selfHostedValue }}
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            <span class="font-medium text-gray-500 dark:text-gray-400">{{ __('Cloud') }}:</span> {{ $hostedValue }}
                        </p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Community --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-4xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center mb-12">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Built in the open') }}
                </h2>
                <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                    {{ __('No private roadmap, no closed issue tracker. Every self-hosted install runs the same code the maintainers ship on.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach(array_filter([
                    ['url' => 'https://github.com/relaticle/relaticle', 'icon' => 'ri-github-fill', 'title' => __('GitHub'), 'desc' => __('Star the repo, browse the source, and open issues or pull requests directly against the codebase.'), 'cta' => __('View the repository'), 'external' => true],
                    ['url' => route('discord'), 'icon' => 'ri-discord-fill', 'title' => __('Discord'), 'desc' => __('Ask deployment questions and get help from other self-hosters and the maintainers.'), 'cta' => __('Join the Discord'), 'external' => true],
                    \Laravel\Pennant\Feature::active(App\Features\Documentation::class)
                        ? ['url' => route('documentation.show', ['type' => 'contributing']), 'icon' => 'ri-git-repository-line', 'title' => __('Contributing'), 'desc' => __('Architecture overview, local setup, and coding conventions for anyone who wants to send a pull request.'), 'cta' => __('Read the contributing guide'), 'external' => false]
                        : null,
                ]) as $card)
                    <a href="{{ $card['url'] }}"
                       @if($card['external']) target="_blank" rel="noopener noreferrer" @endif
                       class="group rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6 transition-all duration-300 hover:border-gray-300 dark:hover:border-white/[0.10] hover:shadow-sm flex flex-col">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-display text-base font-semibold text-gray-900 dark:text-white inline-flex items-center gap-2">
                                <x-dynamic-component :component="$card['icon']" class="w-4 h-4"/>
                                {{ $card['title'] }}
                            </h3>
                            <x-ri-arrow-right-up-line class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 transition-all duration-300"/>
                        </div>
                        <p class="text-[13px] leading-relaxed text-gray-500 dark:text-gray-400 mb-5">{{ $card['desc'] }}</p>
                        <span class="mt-auto text-xs font-medium text-gray-900 dark:text-white group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                            {{ $card['cta'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-10">
                <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                    {{ __('Self-hosting questions, answered') }}
                </h2>
            </div>

            <x-marketing.faq-accordion :faqs="$faqs" id-prefix="self-hosted-faq" />
        </div>
    </section>

    {{-- CTA --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">
                {{ __('Run it your way') }}
            </h2>
            <p class="mt-4 text-base text-gray-500 dark:text-gray-400 leading-relaxed">
                {{ __('Deploy the open-source image on your own server, or skip the ops and let Relaticle run it for you.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
                    {{ __('Deploy self-hosted') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('register') }}">
                    {{ __('Try Relaticle Cloud') }}
                </x-marketing.button>
            </div>
        </div>
    </section>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
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
</x-guest-layout>
