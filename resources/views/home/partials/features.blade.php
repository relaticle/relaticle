@php
    $cardBase = 'group feat-card rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] transition-all duration-300 hover:border-gray-300 dark:hover:border-white/[0.10] hover:shadow-sm';
    $cardTitle = 'font-display text-lg font-medium text-gray-900 dark:text-white mb-2';
    $cardDesc = 'text-[13px] leading-relaxed text-gray-500 dark:text-gray-400';
    $mcpToolCount = \App\Support\CompetitorFacts::mcpToolCount();
@endphp

<section id="features" class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <div class="max-w-6xl mx-auto px-6 lg:px-8 relative">

        <div class="max-w-2xl mx-auto text-center mb-16 md:mb-20">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.03] backdrop-blur-sm mb-6 shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">Features</span>
            </div>
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                One CRM. Three ways to work.
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                Work in the app, <a href="{{ route('ai') }}" class="text-gray-700 dark:text-gray-300 underline decoration-gray-300 dark:decoration-gray-600 underline-offset-4 hover:text-primary dark:hover:text-primary-400 hover:decoration-primary transition-colors">ask {{ config('chat.assistant_name') }} in chat</a>, or connect your agents. Keep your team working from the same customer data.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-2">

            {{-- Agent-Native Infrastructure, 2col 2row: external agents (MCP / REST) --}}
            <div class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-2 lg:row-span-2 overflow-hidden flex flex-col">
                <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white mb-2 inline-flex items-start gap-2">
                    <x-ri-git-merge-line class="mt-1.5 w-4 h-4 shrink-0 text-primary dark:text-primary-400"/>
                    Connect your AI agents
                </h3>
                <p class="{{ $cardDesc }} max-w-md">
                    Give MCP-compatible agents access to your CRM through {{ $mcpToolCount }} tools. Build custom integrations with the REST API.
                </p>

                @include('home.partials.agent-network')
            </div>

            {{-- Built-in AI Chat: the in-app conversational agent --}}
            <div id="card-builtin-ai" class="{{ $cardBase }} p-6 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-chat-smile-3-line id="ai-sparkle" class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Built-in AI chat
                </h3>
                <p class="{{ $cardDesc }}">
                    Ask {{ config('chat.assistant_name') }} about your CRM and make changes through chat. Review proposed updates and deletions before they run.
                </p>
                {{-- Mini chat-bubble preview (uses ai-fill for staggered width animation) --}}
                <div class="mt-4 space-y-2">
                    <div class="flex items-start gap-2">
                        <div class="w-4 h-4 rounded-full bg-gray-200 dark:bg-white/[0.1] shrink-0 mt-px"></div>
                        <div class="ai-line h-3 rounded-md bg-gray-100 dark:bg-white/[0.04] w-3/5 overflow-hidden"><div class="ai-fill h-full rounded-md bg-gray-300/60 dark:bg-white/[0.12] w-0"></div></div>
                    </div>
                    <div class="flex items-start gap-2 justify-end">
                        <div class="ai-line h-3 rounded-md bg-primary/[0.08] dark:bg-primary/[0.12] w-3/4 overflow-hidden"><div class="ai-fill h-full rounded-md bg-primary/30 dark:bg-primary/40 w-0"></div></div>
                        <div class="w-4 h-4 rounded-full bg-gray-900 dark:bg-white/[0.18] shrink-0 mt-px flex items-center justify-center">
                            <x-ri-sparkling-2-fill class="w-2 h-2 text-white dark:text-gray-200"/>
                        </div>
                    </div>
                    <div class="ml-6 ai-line h-2 rounded-full bg-primary/[0.07] dark:bg-primary/[0.12] w-1/2 overflow-hidden"><div class="ai-fill h-full rounded-full bg-primary/15 dark:bg-primary/25 w-0"></div></div>
                </div>

                {{-- A text link, not a clickable card: no other card in this grid is an
                     anchor, and making one of them one breaks the grid's contract. --}}
                <a href="{{ route('ai') }}"
                   class="group mt-4 inline-flex items-center gap-1 text-xs font-medium text-primary dark:text-primary-400 hover:gap-1.5 transition-all">
                    Meet {{ config('chat.assistant_name') }}
                    <x-ri-arrow-right-line class="w-3 h-3"/>
                </a>
            </div>

            {{-- Customizable Data Model --}}
            <div id="card-data" class="{{ $cardBase }} p-6 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-stack-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Custom fields
                </h3>
                <p class="{{ $cardDesc }}">
                    Capture the details that matter to your business. Add custom fields, connect related records, and show fields only when relevant.
                </p>
                <div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 space-y-2">
                    @foreach([['Text', 'Company name...', false], ['Select', 'Industry', true]] as [$label, $placeholder, $hasArrow])
                        <div class="field-row flex items-center gap-2">
                            <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</div>
                            <div class="flex-1 h-6 rounded bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 flex items-center {{ $hasArrow ? 'justify-between' : '' }} text-[10px] text-gray-500 dark:text-gray-400">
                                <span>{{ $placeholder }}</span>
                                @if($hasArrow)<x-ri-arrow-down-s-line class="w-3 h-3"/>@endif
                            </div>
                        </div>
                    @endforeach
                    <div class="field-row flex items-center gap-2">
                        <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">Toggle</div>
                        <div class="w-8 h-4 rounded-full bg-primary relative">
                            <div class="absolute right-0.5 top-0.5 w-3 h-3 rounded-full bg-white shadow-sm"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Company Management --}}
            <div class="relative {{ $cardBase }} p-6 overflow-hidden">
                <div class="absolute w-32 h-32 bg-primary/10 dark:bg-primary/15 rounded-full blur-2xl" style="top: -2rem; left: -2rem;"></div>
                <div class="relative">
                    <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                        <x-ri-building-2-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                        Company profiles
                    </h3>
                    <p class="{{ $cardDesc }}">See every account in context. Keep company details, people, and deals together so your team can prepare for the next conversation.</p>
                </div>
            </div>

            {{-- People Management --}}
            <div class="{{ $cardBase }} p-6">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-user-star-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    People profiles
                </h3>
                <p class="{{ $cardDesc }}">Know who you're talking to. Connect people to their companies, keep relationship notes, and find the right person with filters.</p>
            </div>

            {{-- Sales Opportunities --}}
            <div id="card-sales" class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-1 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-funds-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Sales pipeline
                </h3>
                <p class="{{ $cardDesc }}">
                    Follow every deal from first contact to close. Move deals through custom stages on a visual board that matches your sales process.
                </p>
                <div class="mt-4 flex gap-1 h-2 rounded-full overflow-hidden">
                    <div class="pipe-seg flex-[3] bg-primary/70 rounded-l-full origin-left"></div>
                    <div class="pipe-seg flex-[2] bg-primary/45 origin-left"></div>
                    <div class="pipe-seg flex-[2] bg-primary/25 origin-left"></div>
                    <div class="pipe-seg flex-[1] bg-gray-200 dark:bg-gray-700 rounded-r-full origin-left"></div>
                </div>
                <div class="mt-1.5 flex justify-between text-[10px] text-gray-500 dark:text-gray-500">
                    @foreach(['Lead', 'Qualified', 'Proposal', 'Won'] as $stage)
                        <span>{{ $stage }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Task Management, 2col --}}
            <div id="card-tasks" class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-2 overflow-hidden">
                <div class="flex flex-col md:flex-row md:gap-6">
                    <div class="md:flex-1">
                        <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white mb-2 inline-flex items-center gap-2">
                            <x-ri-layout-masonry-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                            Task management
                        </h3>
                        <p class="{{ $cardDesc }}">
                            Turn follow-ups into clear next steps. Assign tasks, set due dates, and link them to people, companies, or deals.
                        </p>
                    </div>
                    <div class="mt-4 md:mt-0 md:flex-1 rounded-lg bg-gray-50 dark:bg-gray-800 p-4 space-y-3">
                        @foreach([
                            [true, 'Send proposal to', '@Acme', 'bg-primary/10 text-primary-700 dark:text-primary-300'],
                            [false, 'Follow up with', '@Sarah', 'bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300'],
                            [false, 'Review Q4 pipeline', 'Due today', 'bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'],
                        ] as [$done, $text, $badge, $badgeClass])
                            <div class="task-row flex items-center gap-3">
                                <div class="w-4 h-4 rounded-full border-2 {{ $done ? 'border-green-500 bg-green-500/20' : 'border-gray-300 dark:border-gray-600' }} flex items-center justify-center shrink-0">
                                    @if($done)<x-ri-check-line class="w-2.5 h-2.5 text-green-600 dark:text-green-400"/>@endif
                                </div>
                                <span class="text-sm {{ $done ? 'text-gray-500 dark:text-gray-500 line-through' : 'text-gray-700 dark:text-gray-300' }}">{{ $text }}</span>
                                <span class="text-[10px] {{ $badgeClass }} px-2 py-0.5 rounded-full shrink-0">{{ $badge }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Simple feature cards row 2 --}}
            @foreach([
                ['ri-team-line', 'Team collaboration', 'Work from shared customer records. Organize teams in separate workspaces and use roles to control who can view or change data.'],
                ['ri-download-cloud-2-line', 'Import and export', 'Bring your records into Relaticle with CSV imports. Map columns, review validation errors, and export your data when you need it.'],
            ] as [$icon, $title, $desc])
                <div class="{{ $cardBase }} p-6">
                    <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                        <x-dynamic-component :component="$icon" class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                        {{ $title }}
                    </h3>
                    <p class="{{ $cardDesc }}">{{ $desc }}</p>
                </div>
            @endforeach

            {{-- Notes & Activity Log, spanning full width on tablet to avoid an orphan gap --}}
            <div class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-1">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-quill-pen-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Notes and activity
                </h3>
                <p class="{{ $cardDesc }}">Keep customer context close to the records it belongs to. Add linked notes and review the activity history to see what changed.</p>
            </div>

            {{-- CTA Card --}}
            <div class="relative {{ $cardBase }} p-6 flex flex-col justify-between md:col-span-2 lg:col-span-1 overflow-hidden">
                <div class="absolute -bottom-8 -right-8 w-32 h-32 bg-primary/10 dark:bg-primary/15 rounded-full blur-2xl"></div>
                <div class="relative">
                    <h3 class="{{ $cardTitle }}">Ready to start?</h3>
                    <p class="{{ $cardDesc }}">Bring your team and agents onto the same CRM.</p>
                </div>
                <div class="relative mt-5">
                    <x-marketing.button size="sm" href="{{ route('login') }}">
                        Start for free
                    </x-marketing.button>
                    <div class="mt-3 flex items-center gap-3 text-[10px] text-gray-500 dark:text-gray-500">
                        <span>No credit card</span><span>&middot;</span><span>2,000+ tests</span><span>&middot;</span><span>AGPL-3.0</span>
                    </div>
                </div>
            </div>

        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var e = [0.22, 1, 0.36, 1];

                // Cards entrance: staggered fade up (visible by default for Lighthouse/no-JS)
                inView('#features > div > .grid', function() {
                    animate('.feat-card', { y: [32, 0] }, { delay: stagger(0.07), duration: 0.6, ease: e });
                }, { amount: 0.1 });

                // Built-in AI Chat: bubbles fill in sequence (user → assistant → suggestion)
                inView('#card-builtin-ai', function() {
                    animate('#card-builtin-ai .ai-fill', { width: ['0%', '100%'] }, { delay: stagger(0.18, { start: 0.3 }), duration: 0.6, ease: e });
                    animate('#ai-sparkle', { scale: [1, 1.2, 1] }, { duration: 0.5, delay: 0.2, ease: e });
                }, { amount: 0.4 });

                // Data Model: form fields slide in from left
                inView('#card-data', function() {
                    animate('#card-data .field-row', { x: [-16, 0] }, { delay: stagger(0.1, { start: 0.3 }), duration: 0.4, ease: e });
                }, { amount: 0.4 });

                // Sales Pipeline: segments scale in from left
                inView('#card-sales', function() {
                    animate('.pipe-seg', { scaleX: [0, 1] }, { delay: stagger(0.12, { start: 0.3 }), duration: 0.6, ease: e });
                }, { amount: 0.4 });

                // Tasks: rows slide in staggered from right
                inView('#card-tasks', function() {
                    animate('.task-row', { x: [20, 0] }, { delay: stagger(0.15, { start: 0.2 }), duration: 0.45, ease: e });
                }, { amount: 0.3 });
            });
        </script>
    </div>
</section>
