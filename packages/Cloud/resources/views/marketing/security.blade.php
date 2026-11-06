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
        ['ri-download-2-line', __('Export'), __('Download every record type as CSV or Excel, custom fields included. The REST API reads the same records. Export works while the workspace is active.')],
        ['ri-delete-bin-line', __('Delete'), __('Ask for deletion at privacy@relaticle.com. An account scheduled for deletion is removed after a 30-day grace period. Records in shared workspaces remain. We delete a paused trial workspace that no one signs in to for 60 days, after two emails to the owner.')],
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
        ['Fathom Analytics', __('Counts page views and intentional events on the website and in the app'), __('Normalized page paths and events, without query strings, account identifiers or CRM content')],
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
            __('Yes. Export every record type as CSV or Excel, read your records through the REST API, or run the same CRM on your own server.'),
        ],
        [
            __('How do I report a security problem?'),
            __('Email security@relaticle.com with what you found and the steps to reproduce it. We aim to acknowledge reports within 48 hours.'),
        ],
    ];

    $linkClass = 'font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white';
@endphp

<x-cloud::guest-layout
    :title="$title"
    :description="$description"
    :ogTitle="$title"
    :ogDescription="$description"
>
    <x-cloud::marketing.hero align="start">
        <x-slot:eyebrow>
            <x-cloud::marketing.eyebrow>{{ __('Security') }}</x-cloud::marketing.eyebrow>
        </x-slot:eyebrow>

        <x-slot:title>
            {{ __('How Relaticle protects your data.') }}
        </x-slot:title>

        <x-slot:aside>
            <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
                {{ __('Where your records live, who can reach them, and what the AI sees. Relaticle is open source, so these controls can be checked rather than taken on trust.') }}
            </p>

            <div class="mt-7 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('policy.show') }}">
                    {{ __('Read the privacy policy') }}
                </x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="#report">
                    {{ __('Report a vulnerability') }}
                </x-cloud::marketing.button>
            </div>
        </x-slot:aside>

        <ul class="bg-rail border-rail grid grid-cols-1 gap-px border-t sm:grid-cols-2 lg:grid-cols-4">
            @foreach($glance as [$icon, $fact, $detail, $anchor])
                <li class="bg-surface">
                    <a href="{{ $anchor }}" class="hover:bg-tint flex h-full flex-col p-6 transition-colors md:p-8">
                        <span class="text-base font-medium text-gray-950 dark:text-white">{{ $fact }}</span>
                        <span class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $detail }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-cloud::marketing.hero>

    @foreach($sections as $index => [$id, $heading, $lead, $items, $link])
        <x-cloud::marketing.frame id="{{ $id }}">
            <x-cloud::marketing.frame-header :label="sprintf('%02d', $index + 1)" :lead="$heading">
                {{ $lead }}
            </x-cloud::marketing.frame-header>

            <div @class(['bg-rail grid grid-cols-1 gap-px', 'md:grid-cols-2' => count($items) === 2, 'md:grid-cols-3' => count($items) === 3, 'sm:grid-cols-2 lg:grid-cols-4' => count($items) === 4])>
                @foreach($items as [$icon, $term, $meaning])
                    <div class="bg-surface p-6 md:p-10">
                        <h3 class="text-lg font-medium text-gray-950 dark:text-white">{{ $term }}</h3>
                        <p class="type-prose mt-2 text-gray-500 dark:text-gray-400">{{ $meaning }}</p>
                    </div>
                @endforeach
            </div>

            @if($link)
                <div class="bg-surface border-rail border-t px-6 py-5 md:px-8">
                    <p class="type-prose text-gray-700 dark:text-gray-300">
                        <a href="{{ $link[0] }}" class="{{ $linkClass }}">{{ $link[1] }}</a>
                    </p>
                </div>
            @endif
        </x-cloud::marketing.frame>
    @endforeach

    <x-cloud::marketing.frame id="providers">
        <x-cloud::marketing.frame-header :label="__('Service providers')" :lead="$hosted ? __('Who handles data for Relaticle Cloud.') : __('Run by its own operator.')">
            {{ $hosted
                ? __('And what each one receives.')
                : __('This install is run by its own operator. It uses only the company logo lookup, which sends a company domain to Google and DuckDuckGo, unless the operator configures other providers.') }}
        </x-cloud::marketing.frame-header>

        @if($hosted)
            <div class="bg-rail grid gap-px">
                <div class="bg-rail hidden gap-px md:grid md:grid-cols-12" aria-hidden="true">
                    <span class="type-label bg-surface px-10 py-3 text-gray-500 dark:text-gray-400 md:col-span-3">{{ __('Provider') }}</span>
                    <span class="type-label bg-surface px-8 py-3 text-gray-500 dark:text-gray-400 md:col-span-4">{{ __('What it does') }}</span>
                    <span class="type-label bg-surface px-8 py-3 text-gray-500 dark:text-gray-400 md:col-span-5">{{ __('What it receives') }}</span>
                </div>
                @foreach($providers as [$provider, $purpose, $data])
                    <div class="bg-rail grid grid-cols-1 gap-px md:grid-cols-12">
                        <p class="bg-surface px-6 py-5 text-sm font-medium text-gray-950 md:col-span-3 md:px-10 dark:text-white">{{ $provider }}</p>
                        <p class="bg-surface px-6 py-5 text-sm leading-relaxed text-gray-700 md:col-span-4 md:px-8 dark:text-gray-300">{{ $purpose }}</p>
                        <p class="bg-surface px-6 py-5 md:col-span-5 md:px-8">
                            <span class="type-label mb-1 block text-gray-500 md:hidden dark:text-gray-400">{{ __('Receives') }}</span>
                            <span class="text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $data }}</span>
                        </p>
                    </div>
                @endforeach
            </div>

            <div class="bg-surface border-rail border-t px-6 py-5 md:px-8">
                <p class="type-prose text-gray-700 dark:text-gray-300">{{ __('A self-hosted install uses only the logo lookup, unless its operator configures the others.') }}</p>
            </div>
        @endif
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="report">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 py-12 md:px-10 md:py-16 lg:col-span-7">
                <x-cloud::marketing.eyebrow class="mb-5">{{ __('Responsible disclosure') }}</x-cloud::marketing.eyebrow>
                <h2 class="type-title text-gray-950 dark:text-white">{{ __('Report a vulnerability') }}</h2>
                <p class="type-prose mt-4 text-gray-500 dark:text-gray-400">{{ __('Email what you found and the steps to reproduce it. Please do not open a public issue. We aim to acknowledge reports within 48 hours.') }}</p>
            </div>
            <div class="bg-surface flex flex-col justify-center gap-3 px-6 py-12 md:px-10 md:py-16 lg:col-span-5">
                <x-cloud::marketing.button href="mailto:security@relaticle.com" class="w-full sm:w-auto">security@relaticle.com</x-cloud::marketing.button>
                <x-cloud::marketing.button variant="secondary" href="{{ route('securityTxt') }}" class="w-full sm:w-auto">security.txt</x-cloud::marketing.button>
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.frame id="security-faq">
        <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
            <div class="bg-surface px-6 pb-6 pt-16 md:px-10 md:pt-24 lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                    <h2 class="type-title text-gray-950 dark:text-white">{{ __('Security questions, answered') }}</h2>
                    <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                        {{ __('Something else on your mind?') }}
                        <a href="{{ route('contact') }}" class="{{ $linkClass }}">{{ __('Get in touch') }}</a>
                    </p>
                </div>
            </div>

            <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-20">
                <x-cloud::marketing.faq-accordion :faqs="$faqs" id-prefix="security-faq" />
            </div>
        </div>
    </x-cloud::marketing.frame>

    <x-cloud::marketing.closing-band :title="__('Open source, so you can check.')" :continuation="__('Read the code, or ask us.')" :note="null">
        <x-cloud::marketing.button href="https://github.com/relaticle/relaticle" icon="ri-github-fill" external>
            {{ __('View on GitHub') }}
        </x-cloud::marketing.button>
        <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
            {{ __('Get in touch') }}
        </x-cloud::marketing.button>
    </x-cloud::marketing.closing-band>

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
</x-cloud::guest-layout>
