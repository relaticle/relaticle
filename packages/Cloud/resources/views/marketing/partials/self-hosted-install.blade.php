@php
    $defaultHost = 'crm.example.com';

    $installLines = [
        ['type' => 'comment', 'text' => __('Download the compose file')],
        ['type' => 'command', 'text' => 'curl -o compose.yml https://raw.githubusercontent.com/relaticle/relaticle/main/compose.yml'],
        ['type' => 'blank'],
        ['type' => 'comment', 'text' => __('Add the two secrets and your address')],
        ['type' => 'command', 'text' => 'cat > .env << EOF', 'bind' => 'heredocLine'],
        ['type' => 'command', 'text' => 'APP_KEY=base64:$(openssl rand -base64 32)', 'continuation' => true, 'bind' => 'appKeyLine'],
        ['type' => 'command', 'text' => 'DB_PASSWORD=$(openssl rand -hex 24)', 'continuation' => true, 'bind' => 'dbPasswordLine'],
        ['type' => 'command', 'text' => "APP_URL=https://{$defaultHost}", 'continuation' => true, 'bind' => 'appUrlLine'],
        ['type' => 'command', 'text' => 'EOF', 'continuation' => true],
        ['type' => 'blank'],
        ['type' => 'comment', 'text' => __('Start Relaticle')],
        ['type' => 'command', 'text' => 'docker compose up -d'],
        ['type' => 'blank'],
        ['type' => 'comment', 'text' => __('Create your admin account')],
        ['type' => 'command', 'text' => 'docker compose exec app php artisan make:filament-user'],
    ];

    $installCommands = collect($installLines)
        ->where('type', 'command')
        ->map(fn (array $line): array => ['text' => $line['text'], 'bind' => $line['bind'] ?? null])
        ->values();
@endphp

<div
    class="feat-card bg-hero-panel border-rail border-t px-3 py-6 sm:px-8 md:py-12"
    data-self-hosted-install
    x-data="{
        host: @js($defaultHost),
        appKey: 'base64:' + btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32)))),
        dbPassword: Array.from(crypto.getRandomValues(new Uint8Array(24)), (byte) => byte.toString(16).padStart(2, '0')).join(''),
        commands: @js($installCommands),
        copied: false,
        copyFailed: false,
        get cleanHost() {
            const host = this.host.trim().replace(/^https?:\/\//, '').replace(/\/.*$/, '');

            return /^[A-Za-z0-9.-]+(:[0-9]+)?$/.test(host) ? host : @js($defaultHost);
        },
        get heredocLine() { return 'cat > .env << \'EOF\'' },
        get appKeyLine() { return 'APP_KEY=' + this.appKey },
        get dbPasswordLine() { return 'DB_PASSWORD=' + this.dbPassword },
        get appUrlLine() { return 'APP_URL=https://' + this.cleanHost },
        get appAddress() { return 'https://' + this.cleanHost + '/app' },
        script() {
            return this.commands.map((line) => line.bind ? this[line.bind] : line.text).join('\n');
        },
        copy() {
            Promise.resolve()
                .then(() => navigator.clipboard.writeText(this.script()))
                .then(() => {
                    this.copyFailed = false;
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                })
                .catch(() => {
                    this.selectTerminal();
                    this.copyFailed = true;
                    setTimeout(() => this.copyFailed = false, 4000);
                });
        },
        selectTerminal() {
            const range = document.createRange();
            range.selectNodeContents(this.$refs.terminal);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
        },
    }"
>
    <div class="grid gap-4 lg:grid-cols-12 lg:gap-6">
        <x-cloud::marketing.window class="flex flex-col text-left lg:col-span-5">
            <div class="border-rail flex items-center gap-3 border-b px-4 py-2.5">
                <span class="type-label text-gray-500 dark:text-gray-400">{{ __('Terminal') }}</span>

                <button
                    type="button"
                    x-on:click="copy()"
                    class="ml-auto inline-flex min-h-7 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-xs font-medium text-gray-700 transition-colors hover:bg-tint hover:text-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:text-gray-300 dark:hover:text-white"
                    data-self-hosted-copy
                >
                    <x-lucide-copy x-show="! copied" class="h-3.5 w-3.5" aria-hidden="true"/>
                    <x-lucide-check x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden="true"/>
                    <span aria-live="polite" x-text="copied ? @js(__('Copied')) : (copyFailed ? @js(__('Press Cmd/Ctrl+C')) : @js(__('Copy')))">{{ __('Copy') }}</span>
                </button>
            </div>

            <label class="border-rail bg-tint flex items-center gap-2 border-b px-4 py-2.5 font-mono text-[13px]">
                <span class="text-gray-500 dark:text-gray-400">{{ __('Your address') }}</span>
                <span class="text-gray-500 dark:text-gray-400" aria-hidden="true">https://</span>
                <input
                    type="text"
                    x-model="host"
                    value="{{ $defaultHost }}"
                    autocomplete="off"
                    spellcheck="false"
                    aria-label="{{ __('The address your CRM will be served from') }}"
                    class="min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-1 py-0.5 text-gray-950 outline-none transition-colors hover:border-gray-300 focus:border-primary focus:bg-surface dark:text-white dark:hover:border-white/20 dark:focus:border-primary-400"
                    data-self-hosted-host
                >
            </label>

            <div x-ref="terminal" data-self-hosted-terminal class="flex-1 overflow-x-auto py-4 pr-10 pl-4 font-mono [mask-image:linear-gradient(to_right,black_calc(100%-2.5rem),transparent)] text-[12px] leading-6 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:text-[13px]" tabindex="0" role="region" aria-label="{{ __('Install commands') }}">
                @foreach($installLines as $line)
                    @if($line['type'] === 'blank')
                        <div class="h-3" aria-hidden="true"></div>
                    @elseif($line['type'] === 'comment')
                        <p class="whitespace-pre text-gray-500 dark:text-gray-400"># {{ $line['text'] }}</p>
                    @else
                        <p class="flex w-max gap-2 whitespace-pre text-gray-800 dark:text-gray-200">
                            <span class="shrink-0 select-none {{ ($line['continuation'] ?? false) ? 'text-gray-400 dark:text-gray-500' : 'text-primary dark:text-primary-400' }}" aria-hidden="true">{{ ($line['continuation'] ?? false) ? '>' : '$' }}</span>
                            @if(isset($line['bind']))
                                <span x-text="{{ $line['bind'] }}">{{ $line['text'] }}</span>
                            @else
                                <span>{{ $line['text'] }}</span>
                            @endif
                        </p>
                    @endif
                @endforeach
            </div>
        </x-cloud::marketing.window>

        <x-cloud::marketing.mac-window class="lg:col-span-7">
            <x-slot:address>
                <span x-text="appAddress">https://{{ $defaultHost }}/app</span>
            </x-slot:address>

            <x-cloud::marketing.theme-image
                name="app-pipeline-preview"
                :alt="__('The opportunities board in Relaticle, served from your own address')"
                sizes="(min-width: 1024px) 640px, 100vw"
                loading="eager"
                class="block h-auto w-full"
            />
        </x-cloud::marketing.mac-window>
    </div>
</div>

<div class="bg-surface border-rail border-t px-6 py-5 md:px-10" data-self-hosted-tls>
    <p class="type-prose text-gray-700 dark:text-gray-300">
        {{ __('The stack serves HTTP on port 80. For HTTPS, put a TLS reverse proxy in front.') }}
        @if($documentationActive)
            <a href="{{ route('documentation.show', ['type' => 'self-hosting']) }}#reverse-proxy-and-ssl" class="{{ $linkClass }}">{{ __('Reverse proxy guide') }}</a>
        @endif
    </p>
</div>
