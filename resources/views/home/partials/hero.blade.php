<section class="relative pt-16 pb-20 md:pt-24 md:pb-32 bg-white dark:bg-black overflow-hidden">

    {{-- ═══════════════════════════════════════════
         Background system — layered depth
    ════════════════════════════════════════════ --}}

    {{-- Layer 1: Soft grid --}}
    <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>


    {{-- ═══════════════════════════════════════════
         Content
    ════════════════════════════════════════════ --}}

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative">
        <div class="flex flex-col items-center gap-8 md:gap-10">

            {{-- ── Badge ── --}}
            <div class="hero-enter hero-enter-1 flex justify-center">
                <a href="{{ route('documentation.show', 'mcp') }}"
                   class="group inline-flex items-center gap-2 rounded-full border border-primary/20 dark:border-primary/30 bg-primary/[0.04] dark:bg-primary/[0.08] px-4 py-1.5 text-xs font-medium text-primary-700 dark:text-primary-300 transition-all duration-200 hover:bg-primary/[0.08] dark:hover:bg-primary/[0.12] hover:border-primary/30 dark:hover:border-primary/40">
                    <x-ri-sparkling-line class="h-3.5 w-3.5"/>
                    <span>Now with 20 MCP tools for AI agents</span>
                    <x-ri-arrow-right-s-line class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5"/>
                </a>
            </div>

            {{-- ── Heading ── --}}
            <div class="text-center max-w-3xl -mt-2">
                <h1 class="hero-enter hero-enter-2 font-display text-[2rem] sm:text-5xl md:text-[3.5rem] lg:text-6xl font-bold text-gray-950 dark:text-white leading-[1.08] tracking-[-0.03em] text-balance">
                    The <span class="relative inline-block pb-2">
                        Open-Source
                        <svg class="absolute -bottom-1 left-0 w-full" height="8" viewBox="0 0 300 8" preserveAspectRatio="none" aria-hidden="true">
                            <path d="M0,4 Q75,0 150,4 T300,4" stroke-width="2.5" fill="none" stroke-linecap="round" style="stroke: color-mix(in oklab, oklch(0.42 0.24 287.23) 60%, transparent);"/>
                        </svg>
                    </span> CRM<br class="hidden sm:block"/>
                    <span class="text-gray-950 dark:text-white">Built for AI Agents</span>
                </h1>

                <p class="hero-enter hero-enter-3 mt-5 sm:mt-6 text-[15px] sm:text-lg text-gray-500 dark:text-gray-400 max-w-xl mx-auto leading-relaxed tracking-[-0.01em]">
                    The open-source CRM that works with your AI, not against it.<br class="hidden sm:block"/>
                    Self-hosted. Full control over your data.
                </p>
            </div>

            {{-- ── CTA Buttons ── --}}
            <div class="hero-enter hero-enter-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-3 w-full sm:w-auto max-w-sm sm:max-w-none mx-auto -mt-2 px-2 sm:px-0">
                <a href="{{ route('register') }}"
                   class="group relative flex h-12 sm:h-[42px] items-center justify-center gap-2 rounded-lg bg-primary px-8 text-sm font-semibold text-white shadow-[0_1px_2px_rgba(0,0,0,0.1),inset_0_1px_0_rgba(255,255,255,0.1)] hover:shadow-[0_4px_16px_var(--color-primary-500)/20] hover:brightness-110 transition-all duration-200">
                    <span>Get started</span>
                    <x-ri-arrow-right-line class="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5"/>
                </a>

                <a href="https://github.com/relaticle/relaticle" target="_blank"
                   class="group flex h-12 sm:h-[42px] items-center justify-center gap-2 rounded-lg border border-gray-200 bg-white px-8 text-sm font-semibold text-gray-700 shadow-[0_1px_2px_rgba(0,0,0,0.04)] hover:shadow-[0_2px_8px_rgba(0,0,0,0.06)] hover:border-gray-300 transition-all duration-200 dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-white dark:hover:bg-white/[0.06] dark:hover:border-white/[0.15]">
                    <x-ri-github-fill class="h-4 w-4"/>
                    <span>GitHub</span>
                </a>
            </div>

            {{-- ── App Preview with tabs ── --}}
            <div class="hero-enter hero-enter-5 relative w-full max-w-5xl mt-8 md:mt-12">

                {{-- Multi-color glow behind mockup --}}
                <div class="absolute -inset-6 md:-inset-12 -z-10 pointer-events-none" aria-hidden="true">
                    <div class="absolute top-[0%] left-[15%] w-[70%] h-[60%] rounded-full bg-primary/[0.04] dark:bg-primary/[0.08] blur-[120px]"></div>
                </div>


                {{-- ── Decorative framing lines ── --}}
                {{-- Center vertical line dropping into tabs --}}
                <div class="hidden md:block absolute left-1/2 -translate-x-1/2 -top-10 w-px h-10 bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                {{-- Full-width horizontal line at top --}}
                <div class="hidden md:block absolute top-0 left-1/2 -translate-x-1/2 w-[100vw] h-px bg-gray-200 dark:bg-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                {{-- Left vertical line --}}
                <div class="hidden md:block absolute top-0 -left-12 w-px bg-gradient-to-b from-gray-200 via-gray-200/60 to-transparent dark:from-white/[0.08] dark:via-white/[0.05] dark:to-transparent pointer-events-none" style="height:70%" aria-hidden="true"></div>
                {{-- Right vertical line --}}
                <div class="hidden md:block absolute top-0 -right-12 w-px bg-gradient-to-b from-gray-200 via-gray-200/60 to-transparent dark:from-white/[0.08] dark:via-white/[0.05] dark:to-transparent pointer-events-none" style="height:70%" aria-hidden="true"></div>

                {{-- Feature Tabs --}}
                <div id="hero-tabs" class="relative z-10 flex items-stretch">
                    {{-- Solid border under tabs --}}
                    <div class="absolute bottom-0 left-0 right-0 h-px bg-gray-200 dark:bg-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    {{-- Dashed line — full screen width --}}
                    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[100vw] h-px pointer-events-none bg-[repeating-linear-gradient(to_right,theme(colors.gray.200)_0,theme(colors.gray.200)_10px,transparent_10px,transparent_18px)] dark:bg-[repeating-linear-gradient(to_right,rgba(255,255,255,0.08)_0,rgba(255,255,255,0.08)_10px,transparent_10px,transparent_18px)]" aria-hidden="true"></div>
                    {{-- Sliding active indicator --}}
                    <div id="hero-tab-indicator" class="hero-tab-indicator absolute bottom-0 h-px bg-primary/80 rounded-full pointer-events-none" aria-hidden="true"></div>
                    <button type="button" data-hero-tab="companies" class="hero-tab active relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap text-gray-800 dark:text-white transition-colors duration-200 cursor-pointer">
                        Companies
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" data-hero-tab="pipeline" class="hero-tab relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors duration-200 cursor-pointer">
                        Pipeline
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" data-hero-tab="ai-agent" class="hero-tab relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors duration-200 cursor-pointer">
                        AI Agent
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" data-hero-tab="custom-fields" class="hero-tab relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors duration-200 cursor-pointer">
                        <span class="sm:hidden">Fields</span><span class="hidden sm:inline">Custom Fields</span>
                    </button>
                </div>

                {{-- Mockup with layered glow border --}}
                <div class="relative z-10 mt-5 [perspective:2000px]">
                    {{-- Left vertical line — tabs to mockup --}}
                    <div class="hidden md:block absolute -top-[110px] left-0 w-px h-[120px] bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    {{-- Right vertical line — tabs to mockup --}}
                    <div class="hidden md:block absolute -top-[110px] right-0 w-px h-[120px] bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    {{-- Layer 3: Wide ambient glow --}}
                    <div class="absolute -inset-3 rounded-3xl bg-gradient-to-b from-black/[0.03] via-black/[0.015] to-transparent dark:from-white/[0.04] dark:via-white/[0.02] dark:to-transparent blur-xl pointer-events-none" aria-hidden="true"></div>
                    {{-- Layer 2: Medium soft halo --}}
                    <div class="absolute -inset-[3px] rounded-[18px] bg-gradient-to-b from-gray-300/40 via-gray-200/20 to-gray-100/5 dark:from-white/[0.07] dark:via-white/[0.03] dark:to-transparent blur-[3px] pointer-events-none" aria-hidden="true"></div>
                    {{-- Layer 1: Crisp thin border --}}
                    <div class="absolute -inset-px rounded-2xl bg-gradient-to-b from-gray-300/50 via-gray-200/30 to-gray-200/10 dark:from-white/[0.12] dark:via-white/[0.06] dark:to-white/[0.02] pointer-events-none" aria-hidden="true"></div>

                    <div class="relative rounded-2xl overflow-hidden bg-white dark:bg-neutral-950 transform-gpu hover:[transform:rotateX(1deg)] transition-transform duration-700 ease-out">

                        {{-- Browser chrome --}}
                        <div class="bg-gradient-to-b from-gray-50 to-gray-100/50 dark:from-neutral-900 dark:to-neutral-900/80 border-b border-gray-200/80 dark:border-white/[0.06] px-4 py-3 flex items-center">
                            <div class="flex gap-2">
                                <div class="w-3 h-3 rounded-full bg-[#FF5F57] dark:bg-[#FF5F57]/60"></div>
                                <div class="w-3 h-3 rounded-full bg-[#FEBC2E] dark:bg-[#FEBC2E]/60"></div>
                                <div class="w-3 h-3 rounded-full bg-[#28C840] dark:bg-[#28C840]/60"></div>
                            </div>
                            <div class="ml-4 flex-1 max-w-sm mx-auto bg-white dark:bg-neutral-800 rounded-lg px-3 py-1.5 text-xs text-gray-400 dark:text-gray-500 flex items-center justify-center border border-gray-100 dark:border-white/[0.04] shadow-[inset_0_1px_2px_rgba(0,0,0,0.04)]">
                                <x-ri-lock-line class="h-3 w-3 text-gray-300 dark:text-gray-600 mr-1.5"/>
                                <span>app.relaticle.com</span>
                            </div>
                        </div>

                        {{-- Screenshot panels — each with unique animation --}}
                        <div class="relative overflow-hidden">
                            <div id="hero-tab-panel-companies" class="hero-tab-panel" data-animation="fade-up">
                                <img data-light-src="{{ asset('images/app-companies-preview.png') }}"
                                     data-dark-src="{{ asset('images/app-companies-preview-dark.png') }}"
                                     src="{{ asset('images/app-companies-preview.png') }}"
                                     alt="Relaticle CRM — Companies"
                                     class="hero-preview-image w-full h-auto"
                                     width="1440"
                                     height="900"
                                     loading="eager">
                            </div>
                            <div id="hero-tab-panel-pipeline" class="hero-tab-panel hidden" data-animation="slide-right">
                                <img data-light-src="{{ asset('images/app-pipeline-preview.png') }}"
                                     data-dark-src="{{ asset('images/app-pipeline-preview-dark.png') }}"
                                     src="{{ asset('images/app-pipeline-preview.png') }}"
                                     alt="Relaticle CRM — Pipeline"
                                     class="hero-preview-image w-full h-auto"
                                     width="1440"
                                     height="900"
                                     loading="lazy">
                            </div>
                            <div id="hero-tab-panel-ai-agent" class="hero-tab-panel hidden" data-animation="scale-in">
                                <img data-light-src="{{ asset('images/app-companies-preview.png') }}"
                                     data-dark-src="{{ asset('images/app-companies-preview-dark.png') }}"
                                     src="{{ asset('images/app-companies-preview.png') }}"
                                     alt="Relaticle CRM — AI Agent"
                                     class="hero-preview-image w-full h-auto"
                                     width="1440"
                                     height="900"
                                     loading="lazy">
                            </div>
                            <div id="hero-tab-panel-custom-fields" class="hero-tab-panel hidden" data-animation="slide-left">
                                <img data-light-src="{{ asset('images/app-custom-fields-preview.png') }}"
                                     data-dark-src="{{ asset('images/app-custom-fields-preview-dark.png') }}"
                                     src="{{ asset('images/app-custom-fields-preview.png') }}"
                                     alt="Relaticle CRM — Custom Fields"
                                     class="hero-preview-image w-full h-auto"
                                     width="1440"
                                     height="900"
                                     loading="lazy">
                            </div>
                            {{-- Bottom fade overlay --}}
                            <div class="absolute bottom-0 left-0 right-0 h-24 bg-gradient-to-t from-white/60 via-white/20 to-transparent dark:from-black/60 dark:via-black/20 dark:to-transparent pointer-events-none"></div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Stats ── --}}
            <div class="hero-enter hero-enter-6 w-full max-w-3xl grid grid-cols-2 md:grid-cols-4 gap-6 md:gap-8 mt-4">
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/[0.06] dark:bg-primary/[0.10] mb-2.5">
                        <x-ri-open-source-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                    </div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">Open Source</div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Free to use & customize</div>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/[0.06] dark:bg-primary/[0.10] mb-2.5">
                        <x-ri-robot-2-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                    </div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">Agent-Native</div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">MCP server with 20 tools</div>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/[0.06] dark:bg-primary/[0.10] mb-2.5">
                        <x-ri-server-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                    </div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">Self-Hosted</div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Own your data & your AI</div>
                </div>
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary/[0.06] dark:bg-primary/[0.10] mb-2.5">
                        <x-ri-layout-grid-line class="w-4 h-4 text-primary dark:text-primary-400"/>
                    </div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">22 Field Types</div>
                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Customize without code</div>
                </div>
            </div>

        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var tabs = document.querySelectorAll('.hero-tab');
        var panels = document.querySelectorAll('.hero-tab-panel');
        var indicator = document.getElementById('hero-tab-indicator');
        var previewImages = document.querySelectorAll('.hero-preview-image');
        var switching = false;

        // Position the sliding indicator on active tab
        function moveIndicator(tab) {
            if (!indicator || !tab) return;
            indicator.style.left = tab.offsetLeft + 'px';
            indicator.style.width = tab.offsetWidth + 'px';
        }

        // Initialize indicator on first active tab
        var firstActive = document.querySelector('.hero-tab.active');
        if (firstActive) moveIndicator(firstActive);

        // Tab click handler
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                if (switching) return;
                var target = this.getAttribute('data-hero-tab');
                var nextPanel = document.getElementById('hero-tab-panel-' + target);
                if (!nextPanel || !nextPanel.classList.contains('hidden')) return;

                switching = true;

                // Update tab text styles
                tabs.forEach(function (t) {
                    t.classList.remove('active', 'text-gray-800', 'dark:text-white');
                    t.classList.add('text-gray-400', 'dark:text-gray-500');
                });
                this.classList.add('active', 'text-gray-800', 'dark:text-white');
                this.classList.remove('text-gray-400', 'dark:text-gray-500');

                // Slide indicator
                moveIndicator(this);

                // Find current visible panel
                var currentPanel = null;
                panels.forEach(function (p) {
                    if (!p.classList.contains('hidden')) currentPanel = p;
                });

                // Animate out current panel
                if (currentPanel) {
                    currentPanel.classList.add('is-leaving');
                    currentPanel.addEventListener('animationend', function handler() {
                        currentPanel.removeEventListener('animationend', handler);
                        currentPanel.classList.add('hidden');
                        currentPanel.classList.remove('is-leaving');

                        // Animate in next panel
                        nextPanel.classList.remove('hidden');
                        nextPanel.classList.add('is-entering');
                        nextPanel.addEventListener('animationend', function handler2() {
                            nextPanel.removeEventListener('animationend', handler2);
                            nextPanel.classList.remove('is-entering');
                            switching = false;
                        });
                    });
                } else {
                    nextPanel.classList.remove('hidden');
                    nextPanel.classList.add('is-entering');
                    nextPanel.addEventListener('animationend', function handler3() {
                        nextPanel.removeEventListener('animationend', handler3);
                        nextPanel.classList.remove('is-entering');
                        switching = false;
                    });
                }
            });
        });

        // Reposition indicator on resize
        window.addEventListener('resize', function () {
            var active = document.querySelector('.hero-tab.active');
            if (active) moveIndicator(active);
        });

        // Dark mode image switching
        function updateAllImages() {
            var isDark = document.documentElement.classList.contains('dark');
            previewImages.forEach(function (img) {
                img.src = isDark ? img.dataset.darkSrc : img.dataset.lightSrc;
            });
        }

        updateAllImages();

        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.attributeName === 'class') {
                    updateAllImages();
                }
            });
        }).observe(document.documentElement, { attributes: true });
    });
</script>
