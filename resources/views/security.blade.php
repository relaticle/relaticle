@php
    $assistantName = (string) config('chat.assistant_name');
    $emailActive = \Relaticle\EmailIntegration\EmailIntegrationServiceProvider::enabled();
    $hosted = \Laravel\Pennant\Feature::active(\App\Features\Billing::class);
    $signupChallenge = \App\Rules\TurnstileChallenge::isEnabled();
    $errorReportsCarryIdentity = (bool) config('sentry.send_default_pii');

    $aiProviders = collect(resolve(\Relaticle\Chat\Services\ModelRegistry::class)->offered())
        ->map(fn (\Relaticle\Chat\Support\ModelDescriptor $model): ?string => match ($model->provider) {
            'anthropic' => 'Anthropic',
            'openai' => 'OpenAI',
            'gemini' => 'Google',
            default => null,
        })
        ->filter()
        ->unique()
        ->values();

    $title = __('Security: where your CRM data lives').' - Relaticle';
    $description = __(
        'How Relaticle protects accounts and workspaces, what the AI sees, which providers handle your data, and how to export or delete it.'
    );

    $account = [
        ['ri-fingerprint-line', __('Passkeys'), __('Sign in with a passkey instead of a password. Passwords are stored hashed, never in plain text.')],
        ['ri-shield-keyhole-line', __('Two-factor authentication'), __('Require an authenticator code on every sign-in that does not use a passkey. You save recovery codes when you turn it on.')],
        ['ri-login-circle-line', __('Google and Microsoft sign-in'), __('Sign in with the Google or Microsoft account you already use for work.')],
        ['ri-logout-box-r-line', __('Session control'), __('Sign out every other browser session from Settings, Security.')],
    ];

    $workspace = [
        ['ri-building-4-line', __('One workspace per record'), __('Every record belongs to one workspace. Each request is checked against that workspace before it reads or writes.')],
        ['ri-team-line', __('Four roles'), __('Owner, Admin, Member and Viewer decide what each person can see and change.')],
        ['ri-history-line', __('A log of every change'), __('The activity log on a record shows each field change and who made it.')],
    ];

    $ai = [
        [
            'ri-sparkling-2-line',
            __('The built-in assistant'),
            $aiProviders->isEmpty()
                ? __('A request to :name, a voice message or an email summary sends the content needed to answer it to an AI provider. :name proposes every change as a card and waits for your approval.', ['name' => $assistantName])
                : __('A request to :name, a voice message or an email summary sends the content needed to answer it to an AI provider: :providers. :name proposes every change as a card and waits for your approval.', ['name' => $assistantName, 'providers' => $aiProviders->join(', ', ' or ')]),
        ],
        [
            'ri-plug-line',
            __('Assistants you connect'),
            __('Claude, ChatGPT and other MCP clients receive only what they request through the tools you authorized, inside the one workspace you picked. Their changes apply directly. Relaticle does not see or store the conversation in your assistant.'),
        ],
        [
            'ri-hard-drive-3-line',
            __('On your own server'),
            __('Self-host Relaticle with your own provider key, or with a local model through Ollama. With a local model, the assistant sends nothing to an outside AI provider.'),
        ],
    ];

    $email = [
        ['ri-mail-check-line', __('Only the account you connect'), __('Relaticle reads mail and calendar events only from an account you connect, and never changes, labels or deletes messages in your mailbox. It sends email and answers invitations only when you do that from Relaticle.')],
        ['ri-lock-2-line', __('Encrypted tokens'), __('Mailbox access tokens are encrypted at rest. Disconnecting deletes them.')],
    ];

    $tokens = [
        ['ri-key-2-line', __('Scoped tokens'), __('A personal access token is stored hashed and carries only the permissions you give it.')],
        ['ri-timer-line', __('Expiring connector access'), __('Connector access tokens expire after 30 days and refresh tokens after 90.')],
        ['ri-close-circle-line', __('Instant revoke'), __('Revoke a token or a connector under Settings, Access Tokens. It stops working at once.')],
    ];

    $ownership = [
        ['ri-download-2-line', __('Export'), __('Download every record type as CSV or Excel, custom fields included. The REST API reads the same records.')],
        ['ri-delete-bin-line', __('Delete'), __('Ask for deletion at privacy@relaticle.com. An account scheduled for deletion is removed after a 30-day grace period, together with its product update subscription. Records in shared workspaces remain.')],
        ['ri-server-line', __('Leave'), __('Relaticle is open source under AGPL-3.0. Move to your own server whenever you want.')],
    ];

    $glance = [
        ['ri-brain-line', __('No training on your data'), __('Relaticle does not train AI models on your CRM data.'), '#ai'],
        ['ri-checkbox-circle-line', __(':name asks first', ['name' => $assistantName]), __('The built-in assistant proposes each change and waits for you.'), '#ai'],
        ['ri-fingerprint-line', __('Passkeys and two-factor'), __('Sign in with a passkey, or require an authenticator code.'), '#account'],
        ['ri-open-source-line', __('Open source'), __('AGPL-3.0. Read the code, or run it on your own server.'), '#data'],
    ];

    $policyLink = [route('policy.show'), __('Read the privacy policy for sharing settings, deletion and the Google Limited Use commitment.')];

    $sections = array_filter([
        ['account', __('Your account'), __('How you sign in, and what protects the account.'), $account, null],
        ['workspace', __('Your workspace'), __('Who can reach a record, and how you see what they did.'), $workspace, null],
        ['ai', __('What the AI sees'), __('Three ways AI touches your records, and what leaves Relaticle in each.'), $ai, null],
        $emailActive ? ['email', __('Email and calendar'), __('What happens when you connect a Google or Microsoft account.'), $email, $policyLink] : null,
        ['access', __('API and connector access'), __('Every token is scoped, and you can cut any of them off.'), $tokens, null],
        ['data', __('Your data stays yours'), __('Export it, delete it, or take it to your own server.'), $ownership, null],
    ]);

    $providers = array_values(array_filter([
        ['Hetzner', __('Hosts the application and its database, in Germany'), __('All workspace data')],
        ['Laravel Forge', __('Manages the server and runs deploys'), __('Administrative access to the server')],
        ['Mailcoach', __('Sends account, notification and product update email'), __('Recipient name, address, message content and usage tags')],
        ['Postmark', __('Delivers that email for Mailcoach'), __('Recipient address and message content')],
        ['Stripe', __('Takes payment for Cloud plans'), __('Billing details. Card numbers go to Stripe directly.')],
        ['Sentry', __('Reports application errors'), $errorReportsCarryIdentity
            ? __('Error reports, which can include your account identity and data from the request that failed.')
            : __('Error reports without your account identity. A report can include data from the request that failed.')],
        ['Fathom Analytics', __('Counts page views on the website and in the app'), __('Page, referrer and signup events, without cookies')],
        $aiProviders->isEmpty() ? null : [$aiProviders->join(', '), __('Run the AI models behind the assistant, voice input and email summaries'), __('The content of that request')],
        $signupChallenge ? ['Cloudflare', __('Checks that a new account is created by a person'), __('Signals from your browser on the create-account step')] : null,
        ['Google, DuckDuckGo', __('Look up a company logo'), __('The company domain')],
        ['Maxforms', __('Hosts the support and feedback forms'), __('What you type into the form')],
        ['Oh Dear', __('Watches uptime and health checks'), __('No customer data')],
    ]));

    $faqs = [
        [
            __('Does Relaticle train AI models on my data?'),
            __('No. Relaticle does not train AI models on your CRM data, does not sell it, and does not use it for advertising.'),
        ],
        [
            __('Is Relaticle SOC 2 or ISO 27001 certified?'),
            __('No. Relaticle holds neither certification today. The code is open source, so the controls on this page can be read rather than taken on trust.'),
        ],
        [
            __('Can I move my data out of Relaticle Cloud?'),
            __('Yes. Export every record type as CSV or Excel, read your records through the REST API, or run the same code on your own server.'),
        ],
        [
            __('How do I report a security problem?'),
            __('Email security@relaticle.com with what you found and the steps to reproduce it. We aim to acknowledge reports within 48 hours.'),
        ],
    ];

    $sectionTitle = 'text-balance font-display text-2xl sm:text-3xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white';
    $sectionLead = 'mt-4 text-balance text-base text-gray-500 dark:text-gray-400 leading-relaxed';
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
            <div class="flex justify-center mb-6">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                    <x-ri-shield-check-line class="h-3.5 w-3.5 text-primary dark:text-primary-400"/>
                    <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">{{ __('Security') }}</span>
                </div>
            </div>

            <h1 class="text-balance font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white tracking-[-0.03em] leading-[1.1]">
                {{ __('How Relaticle protects your data') }}
            </h1>

            <p class="mt-5 text-balance text-base md:text-lg text-gray-500 dark:text-gray-400 leading-relaxed max-w-2xl mx-auto">
                {{ __('Where your records live, who can reach them, and what the AI sees. Relaticle is open source, so most of this page can be checked in the code.') }}
            </p>

            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="{{ route('policy.show') }}">
                    {{ __('Read the privacy policy') }}
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="#report">
                    {{ __('Report a vulnerability') }}
                </x-marketing.button>
            </div>
        </div>

        {{-- At a glance: the four answers a buyer looks for first, each linked to its section --}}
        <div class="relative mx-auto mt-14 max-w-5xl px-6 lg:px-8">
            <ul class="grid grid-cols-1 gap-px overflow-hidden rounded-2xl border border-gray-200/80 bg-gray-200/80 text-left shadow-[0_1px_2px_rgba(0,0,0,0.03)] sm:grid-cols-2 lg:grid-cols-4 dark:border-white/[0.08] dark:bg-white/[0.08]">
                @foreach($glance as [$icon, $fact, $detail, $anchor])
                    <li class="bg-white dark:bg-gray-950">
                        <a href="{{ $anchor }}" class="group flex h-full flex-col p-5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <x-dynamic-component :component="$icon" class="h-5 w-5 shrink-0 text-primary dark:text-primary-400"/>
                            <span class="mt-3 font-display text-sm font-semibold text-gray-900 dark:text-white">{{ $fact }}</span>
                            <span class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $detail }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- One row per topic: the heading on the left, the facts on the right --}}
    <section class="bg-white dark:bg-gray-950 pb-8 md:pb-12">
        <div class="max-w-5xl mx-auto px-6 lg:px-8">
            @foreach($sections as [$id, $heading, $lead, $items, $link])
                <div id="{{ $id }}" class="grid scroll-mt-24 grid-cols-1 gap-x-12 gap-y-8 border-t border-gray-200/80 py-12 md:grid-cols-3 md:py-16 dark:border-white/[0.06]">
                    <div>
                        <h2 class="text-balance font-display text-xl sm:text-2xl font-bold tracking-[-0.02em] text-gray-950 dark:text-white">{{ $heading }}</h2>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 leading-relaxed">{{ $lead }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <dl class="space-y-7">
                            @foreach($items as [$icon, $term, $meaning])
                                <div class="flex gap-4">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/[0.08] dark:bg-primary/[0.15]">
                                        <x-dynamic-component :component="$icon" class="h-4.5 w-4.5 text-primary dark:text-primary-400"/>
                                    </div>
                                    <div>
                                        <dt class="font-display text-base font-semibold text-gray-900 dark:text-white">{{ $term }}</dt>
                                        <dd class="mt-1 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">{{ $meaning }}</dd>
                                    </div>
                                </div>
                            @endforeach
                        </dl>

                        @if($link)
                            <p class="mt-7 pl-13 text-sm">
                                <a href="{{ $link[0] }}" class="font-medium text-primary dark:text-primary-400 hover:underline">{{ $link[1] }}</a>
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Providers --}}
    <section id="providers" class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-4xl mx-auto px-6 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="{{ $sectionTitle }}">{{ __('Service providers') }}</h2>
                <p class="{{ $sectionLead }}">
                    {{ $hosted
                        ? __('The companies that handle data for Relaticle Cloud, and what each one receives.')
                        : __('This install is run by its own operator. It uses only the company logo lookup, which sends a company domain to Google and DuckDuckGo, unless the operator configures other providers.') }}
                </p>
            </div>

            @if($hosted)
            <div class="mt-14 overflow-hidden rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02]">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Service providers for Relaticle Cloud') }}</caption>
                    <thead class="hidden sm:table-header-group">
                        <tr class="border-b border-gray-200/80 dark:border-white/[0.06]">
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('Provider') }}</th>
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('What it does') }}</th>
                            <th scope="col" class="px-5 py-3 font-display font-semibold text-gray-900 dark:text-white">{{ __('What it receives') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200/80 dark:divide-white/[0.06]">
                        @foreach($providers as [$provider, $purpose, $data])
                            <tr class="block px-5 py-4 sm:table-row sm:p-0">
                                <th scope="row" class="block font-medium text-gray-900 dark:text-white sm:table-cell sm:whitespace-nowrap sm:px-5 sm:py-3">{{ $provider }}</th>
                                <td class="mt-1 block text-gray-600 dark:text-gray-400 sm:mt-0 sm:table-cell sm:px-5 sm:py-3">{{ $purpose }}</td>
                                <td class="mt-3 block text-gray-600 dark:text-gray-400 sm:mt-0 sm:table-cell sm:px-5 sm:py-3">
                                    <span class="mb-0.5 block text-[11px] font-medium uppercase tracking-wider text-gray-400 dark:text-gray-500 sm:hidden">{{ __('Receives') }}</span>
                                    {{ $data }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('A self-hosted install uses only the logo lookup, unless its operator configures the others.') }}
            </p>
            @endif
        </div>
    </section>

    {{-- Report --}}
    <section id="report" class="py-20 md:py-28 bg-white dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8 text-center">
            <h2 class="{{ $sectionTitle }}">{{ __('Report a vulnerability') }}</h2>
            <p class="{{ $sectionLead }} max-w-xl mx-auto">
                {{ __('Email what you found and the steps to reproduce it. Please do not open a public issue. We aim to acknowledge reports within 48 hours.') }}
            </p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <x-marketing.button href="mailto:security@relaticle.com">
                    security@relaticle.com
                </x-marketing.button>
                <x-marketing.button variant="secondary" href="{{ route('securityTxt') }}">
                    security.txt
                </x-marketing.button>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-20 md:py-28 bg-gray-50 dark:bg-gray-950">
        <div class="max-w-3xl mx-auto px-6 lg:px-8">
            <div class="text-center mb-10">
                <h2 class="{{ $sectionTitle }}">{{ __('Security questions, answered') }}</h2>
            </div>

            <x-marketing.faq-accordion :faqs="$faqs" id-prefix="security-faq" />
        </div>
    </section>

    @php
        $schema = (new \Spatie\SchemaOrg\Graph())
            ->webPage(fn ($webPage) => $webPage
                ->name($title)
                ->description($description)
                ->url(route('security')))
            ->fAQPage(fn ($faqPage) => $faqPage
                ->mainEntity(collect($faqs)->map(fn (array $faq) => \Spatie\SchemaOrg\Schema::question()
                    ->name($faq[0])
                    ->acceptedAnswer(\Spatie\SchemaOrg\Schema::answer()->text($faq[1])))->all()))
            ->breadcrumbList(fn ($list) => $list
                ->itemListElement([
                    \Spatie\SchemaOrg\Schema::listItem()->position(1)->name('Relaticle')->item(url('/')),
                    \Spatie\SchemaOrg\Schema::listItem()->position(2)->name(__('Security'))->item(route('security')),
                ]));
    @endphp

    {!! $schema->toScript() !!}
</x-guest-layout>
