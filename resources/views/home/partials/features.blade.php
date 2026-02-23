<!-- Bento Grid Features Section -->
<section id="features" class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <!-- Subtle gradient background elements -->
    <div class="absolute -bottom-64 left-0 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>
    <div class="absolute -top-32 right-0 w-80 h-80 bg-primary/3 dark:bg-primary/5 rounded-full blur-3xl"></div>

    <div class="container max-w-6xl mx-auto px-6 lg:px-8 relative">
        <!-- Section Header -->
        <div class="max-w-3xl mx-auto text-center mb-16 md:mb-20">
            <span class="inline-block px-3 py-1 bg-white dark:bg-gray-900 rounded-full text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                Features
            </span>
            <h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white" style="text-wrap: balance">
                Built for humans. Accessible to AI.
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
                20 MCP tools, a REST API, and 22 custom field types. Your team and your AI agents work from the same source of truth.
            </p>
        </div>

        <!-- Bento Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">

            {{-- ============================================================ --}}
            {{-- Agent-Native Infrastructure — Large card (2 col, 2 row)      --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-8 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm md:col-span-2 lg:col-span-2 lg:row-span-2 overflow-hidden">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-code-ai-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Agent-Native Infrastructure
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed max-w-md">
                    Connect any AI agent through the MCP server with 20 tools, or build custom integrations with the REST API. Full CRUD, custom field support, and schema discovery built in.
                </p>

                <!-- Connection flow diagram -->
                <div class="mt-6 rounded-lg bg-gray-50 dark:bg-gray-800/80 p-5 overflow-hidden">
                    <style>
                        #flow-desktop .fn, #flow-mobile .fn { opacity: 0; }
                    </style>

                    <!-- Mobile: vertical stack -->
                    <div id="flow-mobile" class="flex flex-col items-center gap-2 sm:hidden">
                        <div class="flex items-center gap-2 flex-wrap justify-center">
                            <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                <x-ri-claude-fill class="w-4 h-4 text-[#D4763C]" />
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Claude</span>
                            </div>
                            <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                <x-ri-openai-fill class="w-4 h-4 text-gray-900 dark:text-gray-200" />
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">ChatGPT</span>
                            </div>
                            <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                <x-ri-gemini-fill class="w-4 h-4 text-blue-500" />
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Gemini</span>
                            </div>
                            <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5">
                                <x-ri-add-line class="w-4 h-4 text-gray-400 dark:text-gray-500" />
                                <span class="text-xs font-medium text-gray-400 dark:text-gray-500">Custom</span>
                            </div>
                        </div>
                        <x-ri-arrow-down-double-line class="w-6 h-6 text-gray-300 dark:text-gray-600" />
                        <div class="fn w-full bg-white dark:bg-gray-700 border border-primary/30 dark:border-primary/40 rounded-lg p-3 shadow-sm shadow-primary/5">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">MCP Server · Connected</span>
                            </div>
                            <div class="flex gap-4 text-[11px]">
                                <span class="text-gray-500 dark:text-gray-400"><span class="font-mono font-medium text-gray-800 dark:text-gray-200">20</span> tools</span>
                                <span class="text-gray-500 dark:text-gray-400">REST API <span class="font-mono font-medium text-gray-800 dark:text-gray-200">v1</span></span>
                                <span class="text-gray-500 dark:text-gray-400">Schema <span class="font-mono font-medium text-emerald-600 dark:text-emerald-400">auto</span></span>
                            </div>
                        </div>
                        <x-ri-arrow-down-double-line class="w-6 h-6 text-gray-300 dark:text-gray-600" />
                        <div class="fn flex gap-2 flex-wrap justify-center text-[11px] text-gray-600 dark:text-gray-300">
                            <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">Contacts</span>
                            <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">Companies</span>
                            <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">Deals</span>
                            <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">Tasks</span>
                            <span class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded px-2 py-1">Notes</span>
                        </div>
                    </div>

                    <!-- Desktop: SVG flow diagram with curved merge -->
                    <div id="flow-desktop" class="hidden sm:block relative">

                        {{-- SVG curves overlay --}}
                        <svg class="absolute inset-0 w-full h-full pointer-events-none z-0" viewBox="0 0 613 188" preserveAspectRatio="none">
                            <defs>
                                <linearGradient id="cg" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#d1d5db"/>
                                    <stop offset="50%" stop-color="oklch(0.6 0.19 275 / 0.5)"/>
                                    <stop offset="100%" stop-color="#d1d5db"/>
                                </linearGradient>
                            </defs>

                            {{-- Left curves: each agent → MCP left edge (246) --}}
                            {{-- Agents at x=120, y=42,81,119,158 | MCP left=246, cy=82 --}}
                            <path class="curve-path" pathLength="1" d="M 120 42 C 183 42, 183 65, 246 65" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 120 81 C 183 81, 183 78, 246 78" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 120 119 C 183 119, 183 90, 246 90" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 120 158 C 183 158, 183 100, 246 100" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.4"/>

                            {{-- Right curves: MCP right edge (386) → each CRM entity --}}
                            {{-- CRM at x=513, y=40,73,105,138,170 --}}
                            <path class="curve-path" pathLength="1" d="M 386 65 C 450 65, 450 40, 513 40" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 386 70 C 430 70, 470 73, 513 73" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 386 82 C 450 82, 450 105, 513 105" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 386 90 C 450 90, 450 138, 513 138" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>
                            <path class="curve-path" pathLength="1" d="M 386 100 C 450 100, 450 170, 513 170" stroke="url(#cg)" stroke-width="1.2" fill="none" opacity="0.6"/>

                        </svg>

                        {{-- Content grid (above SVG) --}}
                        <div class="relative z-10 grid gap-0" style="grid-template-columns: 120px 1fr minmax(140px, auto) 1fr 100px;">
                            {{-- Agents column --}}
                            <div class="space-y-2 py-1">
                                <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-medium mb-2">Agents</div>
                                <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2.5 py-1.5 shadow-sm">
                                    <x-ri-claude-fill class="w-3.5 h-3.5 text-[#D4763C]" />
                                    <span class="text-[11px] font-medium text-gray-700 dark:text-gray-300">Claude</span>
                                </div>
                                <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2.5 py-1.5 shadow-sm">
                                    <x-ri-openai-fill class="w-3.5 h-3.5 text-gray-900 dark:text-gray-200" />
                                    <span class="text-[11px] font-medium text-gray-700 dark:text-gray-300">ChatGPT</span>
                                </div>
                                <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-2.5 py-1.5 shadow-sm">
                                    <x-ri-gemini-fill class="w-3.5 h-3.5 text-blue-500" />
                                    <span class="text-[11px] font-medium text-gray-700 dark:text-gray-300">Gemini</span>
                                </div>
                                <div class="fn flex items-center gap-2 bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg px-2.5 py-1.5">
                                    <x-ri-add-line class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500" />
                                    <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500">Custom</span>
                                </div>
                            </div>

                            {{-- Spacer for curves --}}
                            <div></div>

                            {{-- MCP Server (center) --}}
                            <div class="fn py-1">
                                <div class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-medium mb-2">MCP Server</div>
                                <div class="bg-white dark:bg-gray-700 border border-primary/30 dark:border-primary/40 rounded-lg p-3 shadow-sm shadow-primary/5">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="text-[10px] font-medium text-emerald-600 dark:text-emerald-400">Connected</span>
                                    </div>
                                    <div class="space-y-1.5">
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="text-gray-500 dark:text-gray-400">Tools</span>
                                            <span class="font-mono font-medium text-gray-800 dark:text-gray-200">20</span>
                                        </div>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="text-gray-500 dark:text-gray-400">REST API</span>
                                            <span class="font-mono font-medium text-gray-800 dark:text-gray-200">v1</span>
                                        </div>
                                        <div class="flex items-center justify-between text-[11px]">
                                            <span class="text-gray-500 dark:text-gray-400">Schema</span>
                                            <span class="font-mono font-medium text-emerald-600 dark:text-emerald-400">auto</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Spacer for curves --}}
                            <div></div>

                            {{-- CRM entities --}}
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

                            // Hide curves initially
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

            {{-- ============================================================ --}}
            {{-- AI-Powered Insights — Standard card with sparkle             --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm overflow-hidden">
                <div class="mb-5">
                    <div class="relative flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-sparkling-2-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    AI-Powered Insights
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    One-click summaries of contacts and deals. AI analyzes notes, tasks, and interactions so you always know what happened and what to do next.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- Customizable Data Model — Standard card with form mockup     --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm overflow-hidden">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-database-2-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Customizable Data Model
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    22 field types including entity relationships, conditional visibility, and per-field encryption.
                </p>

                <!-- Mini form mockup -->
                <div class="mt-4 rounded-lg bg-gray-50 dark:bg-gray-800 p-3 space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">Text</div>
                        <div class="flex-1 h-6 rounded bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 flex items-center text-[10px] text-gray-400">Company name...</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">Select</div>
                        <div class="flex-1 h-6 rounded bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 px-2 flex items-center justify-between text-[10px] text-gray-400">
                            <span>Industry</span>
                            <x-ri-arrow-down-s-line class="w-3 h-3" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="w-14 text-[10px] text-gray-500 dark:text-gray-400 shrink-0">Toggle</div>
                        <div class="w-8 h-4 rounded-full bg-primary relative">
                            <div class="absolute right-0.5 top-0.5 w-3 h-3 rounded-full bg-white shadow-sm"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- Company Management — Standard card                           --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-building-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Company Management
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Track companies with detailed profiles, linked contacts, and opportunity history. See the full picture at a glance.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- People Management — Standard card                            --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-contacts-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    People Management
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Rich contact profiles with interaction history, notes, and linked companies. Find anyone with advanced search and filters.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- Sales Opportunities — Standard card with pipeline bar        --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm overflow-hidden">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-exchange-funds-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Sales Opportunities
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Manage your pipeline with custom stages, lifecycle tracking, and win/loss analysis.
                </p>

                <!-- Mini pipeline bar -->
                <div class="mt-4 flex gap-1 h-2 rounded-full overflow-hidden">
                    <div class="flex-[3] bg-primary/70 rounded-l-full"></div>
                    <div class="flex-[2] bg-primary/45"></div>
                    <div class="flex-[2] bg-primary/25"></div>
                    <div class="flex-[1] bg-gray-200 dark:bg-gray-700 rounded-r-full"></div>
                </div>
                <div class="mt-1.5 flex justify-between text-[10px] text-gray-400 dark:text-gray-500">
                    <span>Lead</span>
                    <span>Qualified</span>
                    <span>Proposal</span>
                    <span>Won</span>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- Task Management — Wide card (2 col) with task list           --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-8 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm md:col-span-2 lg:col-span-2 overflow-hidden">
                <div class="flex flex-col sm:flex-row sm:gap-8">
                    <div class="sm:flex-1">
                        <div class="mb-5">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                                <x-ri-task-line class="w-5 h-5" />
                            </div>
                        </div>
                        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                            Task Management
                        </h3>
                        <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                            Create, assign, and track tasks linked to contacts, companies, and deals. Your AI agent can create follow-ups automatically.
                        </p>
                    </div>

                    <!-- Mini task list illustration -->
                    <div class="mt-5 sm:mt-0 sm:flex-1 rounded-lg bg-gray-50 dark:bg-gray-800 p-4 space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="w-4 h-4 rounded-full border-2 border-green-500 bg-green-500/20 flex items-center justify-center shrink-0">
                                <x-ri-check-line class="w-2.5 h-2.5 text-green-600 dark:text-green-400" />
                            </div>
                            <span class="text-sm text-gray-400 dark:text-gray-500 line-through">Send proposal to</span>
                            <span class="text-[10px] bg-primary/10 text-primary-700 dark:text-primary-300 px-2 py-0.5 rounded-full shrink-0">@Acme</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-4 h-4 rounded-full border-2 border-gray-300 dark:border-gray-600 shrink-0"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Follow up with</span>
                            <span class="text-[10px] bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-2 py-0.5 rounded-full shrink-0">@Sarah</span>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-4 h-4 rounded-full border-2 border-gray-300 dark:border-gray-600 shrink-0"></div>
                            <span class="text-sm text-gray-700 dark:text-gray-300">Review Q4 pipeline</span>
                            <span class="text-[10px] bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 px-2 py-0.5 rounded-full shrink-0">Due today</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================================ --}}
            {{-- Team Collaboration — Standard card                           --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-team-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Team Collaboration
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Multi-workspace support with role-based permissions and 5-layer authorization. Every team member sees exactly what they should.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- Import & Export — Standard card                              --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-swap-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Import & Export
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Migrate from any CRM with CSV imports. Column mapping, validation, and error handling included. Export anytime — your data is yours.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- Notes & Activity Log — Standard card                         --}}
            {{-- ============================================================ --}}
            <div class="group relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 transition-all duration-300 hover:border-gray-200 dark:hover:border-gray-700 hover:shadow-sm">
                <div class="mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800 text-primary dark:text-primary-400 group-hover:bg-primary/10 dark:group-hover:bg-primary/20 transition-colors duration-300">
                        <x-ri-sticky-note-line class="w-5 h-5" />
                    </div>
                </div>
                <h3 class="font-display text-lg font-medium text-black dark:text-white mb-2 group-hover:text-primary dark:group-hover:text-primary-400 transition-colors duration-300">
                    Notes & Activity Log
                </h3>
                <p class="text-gray-600 dark:text-gray-300 text-sm leading-relaxed">
                    Capture notes linked to any record. Your AI agent can log meeting notes automatically. Search and retrieve context instantly.
                </p>
            </div>

            {{-- ============================================================ --}}
            {{-- CTA Card — Gradient accent card                              --}}
            {{-- ============================================================ --}}
            <div class="relative bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-xl p-6 flex flex-col justify-between md:col-span-2 lg:col-span-1 overflow-hidden">
                <!-- Subtle primary accent glow -->
                <div class="absolute -bottom-8 -right-8 w-32 h-32 bg-primary/10 dark:bg-primary/15 rounded-full blur-2xl"></div>
                <div class="relative">
                    <h3 class="font-display text-lg font-semibold text-black dark:text-white mb-2">Ready to start?</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">Give your AI agents a CRM they can actually use.</p>
                </div>
                <div class="relative mt-5">
                    <a href="{{ route('register') }}" class="group/cta inline-flex items-center gap-2 bg-primary hover:bg-primary-600 text-white font-medium px-5 py-2.5 rounded-md text-sm transition-all duration-300">
                        <span>Start for free</span>
                        <x-ri-arrow-right-line class="h-3.5 w-3.5 transition-transform duration-300 group-hover/cta:translate-x-1" />
                    </a>
                    <div class="mt-3 flex items-center gap-3 text-[10px] text-gray-500 dark:text-gray-400">
                        <span>No credit card</span>
                        <span>&middot;</span>
                        <span>900+ tests</span>
                        <span>&middot;</span>
                        <span>AGPL-3.0</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>
