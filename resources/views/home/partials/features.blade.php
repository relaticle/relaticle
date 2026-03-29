@php
    $cardBase = 'group feat-card rounded-xl border border-gray-200/80 dark:border-white/[0.06] bg-white dark:bg-white/[0.02] transition-all duration-300 hover:border-gray-300 dark:hover:border-white/[0.10] hover:shadow-sm';
    $cardTitle = 'font-display text-lg font-medium text-gray-900 dark:text-white mb-2';
    $cardDesc = 'text-[13px] leading-relaxed text-gray-500 dark:text-gray-400';
@endphp

<section id="features" class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <div class="max-w-6xl mx-auto px-6 lg:px-8 relative">

        <div class="max-w-2xl mx-auto text-center mb-16 md:mb-20">
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.03] backdrop-blur-sm mb-6 shadow-[0_1px_2px_rgba(0,0,0,0.03)]">
                <span class="uppercase tracking-wider text-[10px] font-medium text-gray-500 dark:text-gray-400">Features</span>
            </div>
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                Built for humans. Accessible to AI.
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                30 MCP tools, a REST API, and 22 custom field types. Your team and your AI agents work from the same source of truth.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-2">

            {{-- Agent-Native Infrastructure — 2col 2row --}}
            <div class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-2 lg:row-span-2 overflow-hidden flex flex-col">
                <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white mb-2 inline-flex items-center gap-2">
                    <x-ri-git-merge-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                    Agent-Native Infrastructure
                </h3>
                <p class="{{ $cardDesc }} max-w-md">
                    Connect any AI agent through the MCP server with 30 tools, or build custom integrations with the REST API. Full CRUD, custom field support, and schema discovery built in.
                </p>

                <div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-800/80 p-5 overflow-hidden flex-1 flex flex-col justify-center">
                    <style>
                        #flow-desktop .fn, #flow-mobile .fn { opacity: 0; }
                        @media (min-width: 1024px) { #flow-mobile { display: none !important; } }
                        @media (max-width: 1023px) { #flow-desktop { display: none !important; } }
                    </style>

                    <div id="flow-mobile" class="flex flex-col items-center gap-2">
                        <div class="flex items-center gap-2 flex-wrap justify-center">
                            @foreach([['ri-claude-fill', 'text-[#D4763C]', 'Claude'], ['ri-openai-fill', 'text-gray-900 dark:text-gray-200', 'ChatGPT'], ['ri-gemini-fill', 'text-blue-500', 'Gemini']] as [$icon, $color, $name])
                                <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                    <x-dynamic-component :component="$icon" class="w-4 h-4 {{ $color }}"/>
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $name }}</span>
                                </div>
                            @endforeach
                            <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                <x-ri-add-line class="w-4 h-4 text-gray-400 dark:text-gray-500"/>
                                <span class="text-xs font-medium text-gray-400 dark:text-gray-500">Custom</span>
                            </div>
                        </div>
                        <x-ri-arrow-down-double-line class="w-6 h-6 text-gray-300 dark:text-gray-600"/>
                        <div class="fn w-full bg-white dark:bg-gray-700 border border-primary/30 dark:border-primary/40 rounded-lg p-3 shadow-sm shadow-primary/5">
                            <div class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400 mb-2">MCP Server · Connected</div>
                            <div class="flex gap-4 text-[11px]">
                                <span class="text-gray-500 dark:text-gray-400"><span class="font-mono font-medium text-gray-800 dark:text-gray-200">30</span> tools</span>
                                <span class="text-gray-500 dark:text-gray-400">REST API <span class="font-mono font-medium text-gray-800 dark:text-gray-200">v1</span></span>
                                <span class="text-gray-500 dark:text-gray-400">Schema <span class="font-mono font-medium text-emerald-600 dark:text-emerald-400">auto</span></span>
                            </div>
                        </div>
                        <x-ri-arrow-down-double-line class="w-6 h-6 text-gray-300 dark:text-gray-600"/>
                        <div class="fn flex gap-2 flex-wrap justify-center text-[11px] text-gray-600 dark:text-gray-300">
                            @foreach(['Contacts', 'Companies', 'Deals', 'Tasks', 'Notes'] as $e)
                                <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">{{ $e }}</span>
                            @endforeach
                        </div>
                    </div>

                    <div id="flow-desktop" class="relative">
                        <svg class="absolute inset-0 w-full h-full pointer-events-none z-0" viewBox="0 0 613 188" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="cg" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#d1d5db"/><stop offset="50%" stop-color="oklch(0.6 0.19 275 / 0.5)"/><stop offset="100%" stop-color="#d1d5db"/>
                                </linearGradient>
                            </defs>
                            @foreach([42,81,119,158] as $i => $y)
                                <path class="curve-path" pathLength="1" d="M 120 {{ $y }} C 183 {{ $y }}, 183 {{ 65 + $i * 12 }}, 246 {{ 65 + $i * 12 }}" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="{{ $i === 3 ? '0.4' : '0.6' }}"/>
                            @endforeach
                            @foreach([[65,40],[70,73],[82,105],[90,138],[100,170]] as [$sy,$ey])
                                <path class="curve-path" pathLength="1" d="M 386 {{ $sy }} C 450 {{ $sy }}, 450 {{ $ey }}, 513 {{ $ey }}" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            @endforeach
                        </svg>

                        <div class="relative z-10 grid gap-0" style="grid-template-columns: 120px 1fr minmax(140px, auto) 1fr 100px;">
                            <div class="space-y-2 py-1">
                                <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-medium mb-2">Agents</div>
                                @foreach([['ri-claude-fill', 'text-[#D4763C]', 'Claude', false], ['ri-openai-fill', 'text-gray-900 dark:text-gray-200', 'ChatGPT', false], ['ri-gemini-fill', 'text-blue-500', 'Gemini', false], ['ri-add-line', 'text-gray-400 dark:text-gray-500', 'Custom', true]] as [$icon, $color, $name, $dashed])
                                    <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border {{ $dashed ? 'border-dashed border-gray-300' : 'border-gray-200' }} dark:border-gray-600 rounded-lg px-2.5 py-1.5 {{ $dashed ? '' : 'shadow-sm' }}">
                                        <x-dynamic-component :component="$icon" class="w-3.5 h-3.5 {{ $color }}"/>
                                        <span class="text-[11px] font-medium {{ $dashed ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300' }}">{{ $name }}</span>
                                    </div>
                                @endforeach
                            </div>
                            <div></div>
                            <div class="fn py-1">
                                <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-medium mb-2">MCP Server</div>
                                <div class="bg-white dark:bg-gray-700 border border-primary/30 dark:border-primary/40 rounded-lg p-3 shadow-sm shadow-primary/5">
                                    <div class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400 mb-2">Connected</div>
                                    <div class="space-y-1.5">
                                        @foreach([['Tools', '20', ''], ['REST API', 'v1', ''], ['Schema', 'auto', 'text-emerald-600 dark:text-emerald-400']] as [$label, $val, $valClass])
                                            <div class="flex items-center justify-between text-[11px]">
                                                <span class="text-gray-500 dark:text-gray-400">{{ $label }}</span>
                                                <span class="font-mono font-medium {{ $valClass ?: 'text-gray-800 dark:text-gray-200' }}">{{ $val }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div></div>
                            <div class="fn py-1">
                                <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-medium mb-2">Your CRM</div>
                                <div class="space-y-1.5">
                                    @foreach(['Contacts', 'Companies', 'Deals', 'Tasks', 'Notes'] as $entity)
                                        <div class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2.5 py-1">
                                            <div class="w-1.5 h-1.5 rounded-full bg-primary/60"></div>
                                            {{ $entity }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var ease = [0.22, 1, 0.36, 1];
                            animate(".curve-path", { pathLength: 0 }, { duration: 0 });
                            inView("#flow-desktop", function() {
                                animate("#flow-desktop .fn", { opacity: [0, 1], y: [12, 0] }, { delay: stagger(0.05), duration: 0.4, ease: ease });
                                animate(".curve-path", { pathLength: [0, 1] }, { duration: 0.8, delay: stagger(0.06, { start: 0.3 }), ease: ease });
                            }, { amount: 0.3 });
                            inView("#flow-mobile", function() {
                                animate("#flow-mobile .fn", { opacity: [0, 1], y: [12, 0] }, { delay: stagger(0.06), duration: 0.4, ease: ease });
                            }, { amount: 0.3 });
                        });
                    </script>
                </div>
            </div>

            {{-- AI-Powered Insights --}}
            <div id="card-ai" class="{{ $cardBase }} p-6 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-lightbulb-flash-line id="ai-sparkle" class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    AI-Powered Insights
                </h3>
                <p class="{{ $cardDesc }}">
                    One-click summaries of contacts and deals. AI analyzes notes, tasks, and interactions so you always know what happened and what to do next.
                </p>
                {{-- Animated insight lines --}}
                <div class="mt-4 space-y-2">
                    <div class="ai-line h-2 rounded-full bg-primary/[0.07] dark:bg-primary/[0.12] overflow-hidden"><div class="ai-fill h-full rounded-full bg-primary/20 dark:bg-primary/30 w-0"></div></div>
                    <div class="ai-line h-2 rounded-full bg-primary/[0.07] dark:bg-primary/[0.12] overflow-hidden"><div class="ai-fill h-full rounded-full bg-primary/15 dark:bg-primary/25 w-0"></div></div>
                    <div class="ai-line h-2 rounded-full bg-primary/[0.07] dark:bg-primary/[0.12] overflow-hidden"><div class="ai-fill h-full rounded-full bg-primary/10 dark:bg-primary/20 w-0"></div></div>
                </div>
            </div>

            {{-- Customizable Data Model --}}
            <div id="card-data" class="{{ $cardBase }} p-6 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-stack-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Customizable Data Model
                </h3>
                <p class="{{ $cardDesc }}">
                    22 field types including entity relationships, conditional visibility, and per-field encryption.
                </p>
                <div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 space-y-2">
                    @foreach([['Text', 'Company name...', false], ['Select', 'Industry', true]] as [$label, $placeholder, $hasArrow])
                        <div class="field-row flex items-center gap-2">
                            <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</div>
                            <div class="flex-1 h-6 rounded bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 flex items-center {{ $hasArrow ? 'justify-between' : '' }} text-[10px] text-gray-400">
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
                        Company Management
                    </h3>
                    <p class="{{ $cardDesc }}">Track companies with detailed profiles, linked contacts, and opportunity history. See the full picture at a glance.</p>
                </div>
            </div>

            {{-- People Management --}}
            <div class="{{ $cardBase }} p-6">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-user-star-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    People Management
                </h3>
                <p class="{{ $cardDesc }}">Rich contact profiles with interaction history, notes, and linked companies. Find anyone with advanced search and filters.</p>
            </div>

            {{-- Sales Opportunities --}}
            <div id="card-sales" class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-1 overflow-hidden">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-funds-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Sales Opportunities
                </h3>
                <p class="{{ $cardDesc }}">
                    Manage your pipeline with custom stages, lifecycle tracking, and win/loss analysis.
                </p>
                <div class="mt-4 flex gap-1 h-2 rounded-full overflow-hidden">
                    <div class="pipe-seg flex-[3] bg-primary/70 rounded-l-full origin-left"></div>
                    <div class="pipe-seg flex-[2] bg-primary/45 origin-left"></div>
                    <div class="pipe-seg flex-[2] bg-primary/25 origin-left"></div>
                    <div class="pipe-seg flex-[1] bg-gray-200 dark:bg-gray-700 rounded-r-full origin-left"></div>
                </div>
                <div class="mt-1.5 flex justify-between text-[10px] text-gray-400 dark:text-gray-500">
                    @foreach(['Lead', 'Qualified', 'Proposal', 'Won'] as $stage)
                        <span>{{ $stage }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Task Management — 2col --}}
            <div id="card-tasks" class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-2 overflow-hidden">
                <div class="flex flex-col md:flex-row md:gap-6">
                    <div class="md:flex-1">
                        <h3 class="font-display text-xl font-semibold text-gray-900 dark:text-white mb-2 inline-flex items-center gap-2">
                            <x-ri-layout-masonry-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                            Task Management
                        </h3>
                        <p class="{{ $cardDesc }}">
                            Create, assign, and track tasks linked to contacts, companies, and deals. Your AI agent can create follow-ups automatically.
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
                                <span class="text-sm {{ $done ? 'text-gray-400 dark:text-gray-500 line-through' : 'text-gray-700 dark:text-gray-300' }}">{{ $text }}</span>
                                <span class="text-[10px] {{ $badgeClass }} px-2 py-0.5 rounded-full shrink-0">{{ $badge }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Simple feature cards row 2 --}}
            @foreach([
                ['ri-team-line', 'Team Collaboration', 'Multi-workspace support with role-based permissions and 5-layer authorization. Every team member sees exactly what they should.'],
                ['ri-download-cloud-2-line', 'Import & Export', 'Migrate from any CRM with CSV imports. Column mapping, validation, and error handling included. Export anytime — your data is yours.'],
            ] as [$icon, $title, $desc])
                <div class="{{ $cardBase }} p-6">
                    <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                        <x-dynamic-component :component="$icon" class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                        {{ $title }}
                    </h3>
                    <p class="{{ $cardDesc }}">{{ $desc }}</p>
                </div>
            @endforeach

            {{-- Notes & Activity Log — spans full width on tablet to avoid orphan gap --}}
            <div class="{{ $cardBase }} p-6 md:col-span-2 lg:col-span-1">
                <h3 class="{{ $cardTitle }} inline-flex items-center gap-2">
                    <x-ri-quill-pen-line class="w-3.5 h-3.5 text-primary dark:text-primary-400"/>
                    Notes & Activity Log
                </h3>
                <p class="{{ $cardDesc }}">Capture notes linked to any record. Your AI agent can log meeting notes automatically. Search and retrieve context instantly.</p>
            </div>

            {{-- CTA Card --}}
            <div class="relative {{ $cardBase }} p-6 flex flex-col justify-between md:col-span-2 lg:col-span-1 overflow-hidden">
                <div class="absolute -bottom-8 -right-8 w-32 h-32 bg-primary/10 dark:bg-primary/15 rounded-full blur-2xl"></div>
                <div class="relative">
                    <h3 class="{{ $cardTitle }}">Ready to start?</h3>
                    <p class="{{ $cardDesc }}">Give your AI agents a CRM they can actually use.</p>
                </div>
                <div class="relative mt-5">
                    <x-marketing.button size="sm" href="{{ route('register') }}">
                        Start for free
                    </x-marketing.button>
                    <div class="mt-3 flex items-center gap-3 text-[10px] text-gray-400 dark:text-gray-500">
                        <span>No credit card</span><span>&middot;</span><span>1,100+ tests</span><span>&middot;</span><span>AGPL-3.0</span>
                    </div>
                </div>
            </div>

        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var e = [0.22, 1, 0.36, 1];

                // Cards entrance — staggered fade up
                document.querySelectorAll('.feat-card').forEach(function(c) { c.style.opacity = '0'; });
                inView('#features .grid', function() {
                    animate('.feat-card', { opacity: [0, 1], y: [32, 0] }, { delay: stagger(0.07), duration: 0.6, ease: e });
                }, { amount: 0.1 });

                // AI Insights — scanning lines fill to random widths
                inView('#card-ai', function() {
                    animate('.ai-fill', { width: ['0%', '85%'] }, { delay: stagger(0.12, { start: 0.3 }), duration: 0.8, ease: e });
                    setTimeout(function() {
                        animate('.ai-fill', { width: ['85%', '60%'] }, { delay: stagger(0.08), duration: 0.5, ease: e });
                        setTimeout(function() {
                            animate('.ai-fill', { width: ['60%', '92%'] }, { delay: stagger(0.1), duration: 0.6, ease: e });
                        }, 600);
                    }, 1000);
                    // Sparkle icon pulse
                    animate('#ai-sparkle', { scale: [1, 1.3, 1], rotate: [0, 15, 0] }, { duration: 0.6, delay: 0.2, ease: e });
                }, { amount: 0.4 });

                // Data Model — form fields slide in from left
                document.querySelectorAll('#card-data .field-row').forEach(function(r) { r.style.opacity = '0'; });
                inView('#card-data', function() {
                    animate('#card-data .field-row', { opacity: [0, 1], x: [-16, 0] }, { delay: stagger(0.1, { start: 0.3 }), duration: 0.4, ease: e });
                }, { amount: 0.4 });

                // Sales Pipeline — segments scale in from left
                document.querySelectorAll('.pipe-seg').forEach(function(s) { s.style.transform = 'scaleX(0)'; });
                inView('#card-sales', function() {
                    animate('.pipe-seg', { scaleX: [0, 1] }, { delay: stagger(0.12, { start: 0.3 }), duration: 0.6, ease: e });
                }, { amount: 0.4 });

                // Tasks — rows slide in staggered from right
                document.querySelectorAll('.task-row').forEach(function(r) { r.style.opacity = '0'; });
                inView('#card-tasks', function() {
                    animate('.task-row', { opacity: [0, 1], x: [20, 0] }, { delay: stagger(0.15, { start: 0.2 }), duration: 0.45, ease: e });
                }, { amount: 0.3 });
            });
        </script>
    </div>
</section>
