<section x-data="heroTabs()" x-init="init()" @resize.window="positionIndicator()" class="relative pt-32 pb-20 md:pt-40 md:pb-32 bg-white dark:bg-black overflow-hidden">

    {{-- Background system — layered depth --}}
    <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]"></div>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative">
        <div class="flex flex-col items-center gap-8 md:gap-10">

            {{-- ── Badge (GitHub) ── --}}
            <div class="flex justify-center">
                <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener"
                   class="group inline-flex items-center gap-2 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm px-4 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition-all duration-200 hover:border-gray-300 dark:hover:border-white/[0.15]">
                    <x-ri-github-fill class="h-3.5 w-3.5"/>
                    <span class="text-gray-900 dark:text-white font-semibold">{{ $formattedGithubStars ?? '1.2K' }}+ stars</span>
                    <span class="w-px h-3 bg-gray-200 dark:bg-white/10"></span>
                    <span>Open Source</span>
                    <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-500"/>
                </a>
            </div>

            {{-- ── Heading ── --}}
            <div class="text-center max-w-3xl">
                <h1 class="font-display leading-[1.08] tracking-[-0.035em] text-balance">
                    <span class="block text-2xl sm:text-[2.5rem] md:text-[3rem] lg:text-[3.25rem] font-normal text-gray-500 dark:text-gray-400">The Open-Source CRM</span>
                    <span class="block text-[2.25rem] sm:text-5xl md:text-[3.5rem] lg:text-[3.75rem] font-extrabold text-gray-950 dark:text-white mt-1.5 sm:mt-2">Built for AI Agents</span>
                </h1>

                <p class="mt-6 sm:mt-7 text-[15px] sm:text-lg text-gray-500 dark:text-gray-400 max-w-xl mx-auto leading-relaxed tracking-[-0.01em]">
                    Connect any AI agent with 30 MCP tools.<br class="hidden sm:block"/>
                    Self-hosted. No per-seat pricing. Yours to own.
                </p>
            </div>

            {{-- ── CTA Buttons ── --}}
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 w-full sm:w-auto max-w-sm sm:max-w-none mx-auto -mt-2 px-2 sm:px-0">
                <x-marketing.button href="{{ route('register') }}" class="group">
                    Start for free
                </x-marketing.button>

                <x-marketing.button variant="secondary" href="{{ route('contact') }}">
                    Get in touch
                </x-marketing.button>
            </div>

            {{-- ── App Preview with tabs ── --}}
            <div class="relative w-full max-w-5xl mt-8 md:mt-12">

                {{-- Multi-color glow behind mockup --}}
                <div class="absolute -inset-6 md:-inset-12 -z-10 pointer-events-none" aria-hidden="true">
                    <div class="absolute top-[0%] left-[15%] w-[70%] h-[60%] rounded-full bg-primary/[0.04] dark:bg-primary/[0.08] blur-[120px]"></div>
                </div>

                {{-- ── Decorative framing lines ── --}}
                <div class="hidden md:block absolute left-1/2 -translate-x-1/2 -top-10 w-px h-10 bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                <div class="hidden md:block absolute top-0 left-1/2 -translate-x-1/2 w-[100vw] h-px bg-gray-200 dark:bg-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                <div class="hidden md:block absolute top-0 -left-12 w-px bg-gradient-to-b from-gray-200 via-gray-200/60 to-transparent dark:from-white/[0.08] dark:via-white/[0.05] dark:to-transparent pointer-events-none" style="height:70%" aria-hidden="true"></div>
                <div class="hidden md:block absolute top-0 -right-12 w-px bg-gradient-to-b from-gray-200 via-gray-200/60 to-transparent dark:from-white/[0.08] dark:via-white/[0.05] dark:to-transparent pointer-events-none" style="height:70%" aria-hidden="true"></div>

                {{-- Feature Tabs --}}
                <div id="hero-tabs" class="relative z-10 flex items-stretch">
                    <div class="absolute bottom-0 left-0 right-0 h-px bg-gray-200 dark:bg-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[100vw] h-px pointer-events-none bg-[repeating-linear-gradient(to_right,theme(colors.gray.200)_0,theme(colors.gray.200)_10px,transparent_10px,transparent_18px)] dark:bg-[repeating-linear-gradient(to_right,rgba(255,255,255,0.08)_0,rgba(255,255,255,0.08)_10px,transparent_10px,transparent_18px)]" aria-hidden="true"></div>
                    <div x-ref="indicator" class="absolute bottom-0 h-px bg-primary/80 rounded-full pointer-events-none transition-[left,width] duration-200" aria-hidden="true"></div>
                    <button type="button" x-ref="tab-companies" x-on:click="switchTab('companies')" :class="tabClasses('companies')" class="relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer">
                        Companies
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" x-ref="tab-pipeline" x-on:click="switchTab('pipeline')" :class="tabClasses('pipeline')" class="relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer">
                        Pipeline
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" x-ref="tab-ai-agent" x-on:click="switchTab('ai-agent')" :class="tabClasses('ai-agent')" class="relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer">
                        AI Agent
                    </button>
                    <div class="w-px self-stretch my-0 bg-gray-200 dark:bg-white/[0.08]" aria-hidden="true"></div>
                    <button type="button" x-ref="tab-custom-fields" x-on:click="switchTab('custom-fields')" :class="tabClasses('custom-fields')" class="relative flex-1 py-2.5 sm:py-3 text-xs sm:text-sm font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer">
                        <span class="sm:hidden">Fields</span><span class="hidden sm:inline">Custom Fields</span>
                    </button>
                </div>

                {{-- Mockup with layered glow border --}}
                <div class="relative z-10 mt-5 [perspective:2000px]">
                    <div class="hidden md:block absolute -top-[110px] left-0 w-px h-[120px] bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    <div class="hidden md:block absolute -top-[110px] right-0 w-px h-[120px] bg-gradient-to-b from-transparent via-gray-200/60 to-gray-200 dark:via-white/[0.04] dark:to-white/[0.08] pointer-events-none" aria-hidden="true"></div>
                    <div class="absolute -inset-3 rounded-3xl bg-gradient-to-b from-black/[0.03] via-black/[0.015] to-transparent dark:from-white/[0.04] dark:via-white/[0.02] dark:to-transparent blur-xl pointer-events-none" aria-hidden="true"></div>
                    <div class="absolute -inset-[3px] rounded-[18px] bg-gradient-to-b from-gray-300/40 via-gray-200/20 to-gray-100/5 dark:from-white/[0.07] dark:via-white/[0.03] dark:to-transparent blur-[3px] pointer-events-none" aria-hidden="true"></div>
                    <div class="absolute -inset-px rounded-2xl bg-gradient-to-b from-gray-300/50 via-gray-200/30 to-gray-200/10 dark:from-white/[0.12] dark:via-white/[0.06] dark:to-white/[0.02] pointer-events-none" aria-hidden="true"></div>

                    <div class="relative rounded-2xl overflow-hidden bg-white dark:bg-neutral-950 transform-gpu hover:[transform:rotateX(1deg)] transition-transform duration-700 ease-out">

                        {{-- Browser chrome --}}
                        <div class="bg-gray-50 dark:bg-neutral-900 border-b border-gray-200/60 dark:border-white/[0.06] px-4 py-2.5 flex items-center justify-between">
                            <div class="flex gap-1.5">
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                            </div>
                            <div class="bg-white/60 dark:bg-white/[0.04] rounded-md px-3 py-1 text-[11px] text-gray-400 dark:text-gray-500">
                                app.relaticle.com
                            </div>
                        </div>

                        {{-- Tab panels — grid stacking for Safari-smooth crossfade --}}
                        <div class="relative grid overflow-hidden">
                            <div x-ref="panel-companies" class="col-start-1 row-start-1">
                                <picture>
                                    <source data-light-srcset="{{ asset('images/app-companies-preview.webp') }}" data-dark-srcset="{{ asset('images/app-companies-preview-dark.webp') }}" srcset="{{ asset('images/app-companies-preview.webp') }}" type="image/webp">
                                    <img data-light-src="{{ asset('images/app-companies-preview.png') }}"
                                         data-dark-src="{{ asset('images/app-companies-preview-dark.png') }}"
                                         src="{{ asset('images/app-companies-preview.png') }}"
                                         alt="Relaticle CRM — Companies"
                                         class="hero-preview-image w-full h-auto"
                                         width="1440"
                                         height="900"
                                         loading="eager"
                                         fetchpriority="high">
                                </picture>
                            </div>
                            <div x-ref="panel-pipeline" class="col-start-1 row-start-1 invisible">
                                <picture>
                                    <source data-light-srcset="{{ asset('images/app-pipeline-preview.webp') }}" data-dark-srcset="{{ asset('images/app-pipeline-preview-dark.webp') }}" srcset="{{ asset('images/app-pipeline-preview.webp') }}" type="image/webp">
                                    <img data-light-src="{{ asset('images/app-pipeline-preview.png') }}"
                                         data-dark-src="{{ asset('images/app-pipeline-preview-dark.png') }}"
                                         src="{{ asset('images/app-pipeline-preview.png') }}"
                                         alt="Relaticle CRM — Pipeline"
                                         class="hero-preview-image w-full h-auto"
                                         width="1440"
                                         height="900"
                                         loading="lazy">
                                </picture>
                            </div>

                            {{-- AI Agent tab --}}
                            <div x-ref="panel-ai-agent" class="col-start-1 row-start-1 invisible">
                                @include('home.partials.hero-agent-preview')
                            </div>

                            <div x-ref="panel-custom-fields" class="col-start-1 row-start-1 invisible">
                                <picture>
                                    <source data-light-srcset="{{ asset('images/app-custom-fields-preview.webp') }}" data-dark-srcset="{{ asset('images/app-custom-fields-preview-dark.webp') }}" srcset="{{ asset('images/app-custom-fields-preview.webp') }}" type="image/webp">
                                    <img data-light-src="{{ asset('images/app-custom-fields-preview.png') }}"
                                         data-dark-src="{{ asset('images/app-custom-fields-preview-dark.png') }}"
                                         src="{{ asset('images/app-custom-fields-preview.png') }}"
                                         alt="Relaticle CRM — Custom Fields"
                                         class="hero-preview-image w-full h-auto"
                                         width="1440"
                                         height="900"
                                         loading="lazy">
                                </picture>
                            </div>
                            {{-- Bottom fade overlay --}}
                            <div class="absolute bottom-0 left-0 right-0 h-24 bg-gradient-to-t from-white/60 via-white/20 to-transparent dark:from-black/60 dark:via-black/20 dark:to-transparent pointer-events-none"></div>
                        </div>
                    </div>
                </div>
            </div>


        </div>
    </div>
</section>

<script>
    function heroTabs() {
        return {
            activeTab: 'companies',
            tabOrder: ['companies', 'pipeline', 'ai-agent', 'custom-fields'],
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ease: [0.16, 1, 0.3, 1],
            duration: 0.35,
            slideDistance: 40,

            init() {
                this.positionIndicator();
                this.updateImages();
                this.observeDarkMode();
            },

            positionIndicator() {
                var tab = this.$refs['tab-' + this.activeTab];
                var indicator = this.$refs.indicator;
                if (!tab || !indicator) return;
                indicator.style.left = tab.offsetLeft + 'px';
                indicator.style.width = tab.offsetWidth + 'px';
            },

            showPanel(panel) {
                panel.classList.remove('invisible');
                panel.style.zIndex = '1';
            },

            hidePanel(panel) {
                panel.classList.add('invisible');
                panel.style.opacity = '';
                panel.style.transform = '';
                panel.style.willChange = '';
                panel.style.zIndex = '';
            },

            cancelPanelAnimations() {
                var self = this;
                this.tabOrder.forEach(function(name) {
                    var panel = self.$refs['panel-' + name];
                    if (!panel) return;
                    if (panel.getAnimations) {
                        panel.getAnimations().forEach(function(a) { a.cancel(); });
                    }
                    panel.style.opacity = '';
                    panel.style.transform = '';
                    panel.style.willChange = '';
                    panel.style.zIndex = '';
                });
            },

            switchTab(target) {
                if (target === this.activeTab) return;

                var previousTab = this.activeTab;
                var direction = this.tabOrder.indexOf(target) > this.tabOrder.indexOf(previousTab) ? 1 : -1;
                var self = this;

                this.activeTab = target;
                this.positionIndicator();

                if (target === 'ai-agent') {
                    this.$dispatch('hero-chat-reset');
                }

                this.cancelPanelAnimations();

                this.tabOrder.forEach(function(name) {
                    var panel = self.$refs['panel-' + name];
                    if (!panel) return;
                    if (name !== target && name !== previousTab) {
                        self.hidePanel(panel);
                    }
                });

                var currentPanel = this.$refs['panel-' + previousTab];
                var nextPanel = this.$refs['panel-' + target];
                if (!currentPanel || !nextPanel) return;

                this.showPanel(nextPanel);

                if (this.reducedMotion) {
                    this.hidePanel(currentPanel);
                    if (target === 'ai-agent') {
                        this.$dispatch('hero-chat-animate');
                    }
                    return;
                }

                currentPanel.style.willChange = 'transform, opacity';
                nextPanel.style.willChange = 'transform, opacity';

                var outX = -direction * this.slideDistance;
                var inX = direction * this.slideDistance;

                animate(currentPanel, {
                    opacity: [1, 0],
                    transform: ['translateX(0px)', 'translateX(' + outX + 'px)']
                }, { duration: this.duration, ease: this.ease });

                animate(nextPanel, {
                    opacity: [0, 1],
                    transform: ['translateX(' + inX + 'px)', 'translateX(0px)']
                }, { duration: this.duration, ease: this.ease }).then(function() {
                    if (self.activeTab !== target) return;
                    self.hidePanel(currentPanel);
                    nextPanel.style.willChange = '';
                    if (target === 'ai-agent') {
                        self.$dispatch('hero-chat-animate');
                    }
                });
            },

            tabClasses(tab) {
                return this.activeTab === tab
                    ? 'text-gray-800 dark:text-white'
                    : 'text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300';
            },

            updateImages() {
                var isDark = document.documentElement.classList.contains('dark');
                this.$root.querySelectorAll('.hero-preview-image').forEach(function(img) {
                    img.src = isDark ? img.dataset.darkSrc : img.dataset.lightSrc;
                });
                this.$root.querySelectorAll('picture source[data-light-srcset]').forEach(function(source) {
                    source.srcset = isDark ? source.dataset.darkSrcset : source.dataset.lightSrcset;
                });
            },

            observeDarkMode() {
                var self = this;
                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.attributeName === 'class') {
                            self.updateImages();
                        }
                    });
                }).observe(document.documentElement, { attributes: true });
            }
        };
    }
</script>
