<section id="community" class="relative py-24 md:py-32 overflow-hidden bg-white dark:bg-gray-950">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(0_0_0/0.04)_1px,transparent_0)] dark:bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.035)_1px,transparent_0)] bg-[size:24px_24px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,black_25%,transparent_100%)]"></div>

    <div class="relative max-w-6xl mx-auto px-6 lg:px-8">
        <div class="max-w-2xl mx-auto text-center mb-16 md:mb-20">
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                Built in the Open
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 max-w-lg mx-auto leading-relaxed">
                Relaticle is AGPL-3.0 open source. Star the repo, join Discord, and help shape the future of agent-native CRM.
            </p>
        </div>

        <div class="max-w-4xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-3 mb-10">
            @php
                $cards = [
                    ['url' => 'https://github.com/relaticle/relaticle', 'icon' => 'ri-github-fill', 'iconClass' => '', 'title' => 'GitHub', 'desc' => 'Star our repo, report issues, and contribute code. Completely open source and free to use.', 'cta' => 'View Repository', 'external' => true],
                    ['url' => route('discord'), 'icon' => 'ri-discord-fill', 'iconClass' => 'text-[#5865F2]', 'title' => 'Discord', 'desc' => 'Chat with developers, get help, and share ideas. Join our growing community of builders.', 'cta' => 'Join Discord', 'external' => true],
                ];

                if (\Laravel\Pennant\Feature::active(\App\Features\Documentation::class)) {
                    $cards[] = ['url' => route('documentation.index'), 'icon' => 'ri-book-open-line', 'iconClass' => 'text-primary dark:text-primary-400', 'title' => 'Documentation', 'desc' => 'Learn how to use Relaticle. Comprehensive guides for users and developers alike.', 'cta' => 'Read the Docs', 'external' => false];
                }
            @endphp
            @foreach($cards as $card)
                <a href="{{ $card['url'] }}" @if($card['external']) target="_blank" rel="noopener noreferrer" @endif
                   class="group rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] p-6 transition-all duration-300 hover:border-gray-300 dark:hover:border-white/[0.10] hover:shadow-sm flex flex-col">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-display text-lg font-medium text-gray-900 dark:text-white inline-flex items-center gap-2">
                            <x-dynamic-component :component="$card['icon']" class="w-3.5 h-3.5 {{ $card['iconClass'] }}"/>
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

        <div class="max-w-3xl mx-auto">
            <div class="rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-gray-50/50 dark:bg-white/[0.015] overflow-hidden">
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-gray-200/60 dark:divide-white/[0.04]">
                    @foreach([['AGPL-3.0', 'Open Source'], ['1,100+', 'Automated Tests'], ['30', 'MCP Tools'], ['Free', 'Forever']] as $index => [$value, $label])
                        <div class="px-6 py-5 text-center @if($index >= 2) border-t border-gray-200/60 dark:border-white/[0.04] md:border-t-0 @endif">
                            <div class="text-lg font-semibold text-gray-900 dark:text-white tracking-tight">{{ $value }}</div>
                            <div class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5 uppercase tracking-wider font-medium">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

</section>
