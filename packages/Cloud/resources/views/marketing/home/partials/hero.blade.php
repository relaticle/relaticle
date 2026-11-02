<x-cloud::marketing.hero x-data="heroTabs()" x-init="init()" x-on:resize.window="positionIndicator()">
    <x-slot:eyebrow>
        <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener"
           aria-label="{{ __('Open Source, :count+ GitHub stars', ['count' => $formattedGithubStars]) }}"
           class="bg-surface border-rail group inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium text-gray-600 transition-colors duration-200 hover:border-gray-300 dark:text-gray-300 dark:hover:border-white/[0.2]">
            <x-ri-github-fill class="h-3.5 w-3.5"/>
            <span>Open Source</span>
            <span class="w-px h-3 bg-gray-200 dark:bg-white/10"></span>
            <span class="inline-flex items-center whitespace-nowrap text-gray-900 dark:text-white font-semibold leading-none">
                <span x-ref="githubStars" class="relative inline-grid tabular-nums" aria-hidden="true">
                    <span class="invisible col-start-1 row-start-1">{{ $formattedGithubStars }}</span>
                    <span class="col-start-1 row-start-1 inline-flex justify-self-end">
                        @foreach(str_split($formattedGithubStars) as $character)
                            @if(ctype_digit($character))
                                <span class="inline-block h-[1em] w-[1ch] overflow-hidden">
                                    <span data-github-star-digit="{{ $character }}" class="flex flex-col text-center leading-none" style="transform: translateY(-{{ (int) $character }}em)">
                                        @for($digit = 0; $digit <= (int) $character; $digit++)
                                            <span class="block h-[1em] leading-none">{{ $digit }}</span>
                                        @endfor
                                    </span>
                                </span>
                            @else
                                <span>{{ $character }}</span>
                            @endif
                        @endforeach
                    </span>
                </span>+ stars
            </span>
            <x-ri-arrow-right-up-line class="h-3 w-3 text-gray-400 dark:text-gray-500"/>
        </a>
    </x-slot:eyebrow>

    <x-slot:title>{{ __('The AI CRM for selling without a sales team') }}</x-slot:title>

    <x-slot:aside>
        <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">
            {{ __('For people who sell to a named list of companies. Work from Claude or ChatGPT, or ask :name, the built-in AI assistant.', ['name' => (string) config('chat.assistant_name')]) }}
        </p>

        <div class="mt-7 flex flex-col gap-3 sm:flex-row">
            <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                {{ __('Start for free') }}
            </x-cloud::marketing.button>

            <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
                {{ __('Get in touch') }}
            </x-cloud::marketing.button>
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('No credit card. Unlimited users. Open source.') }}</p>

        {{-- Watch demo CTA (only renders when video asset exists) --}}
        @if(file_exists(public_path('videos/hero-demo.mp4')))
            <button
                type="button"
                x-data
                @click="$dispatch('open-hero-demo')"
                class="inline-flex items-center gap-1.5 text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors mt-5"
            >
                <x-ri-play-circle-line class="w-4 h-4"/>
                Watch 30s demo
                <x-ri-arrow-right-up-line class="w-3 h-3"/>
            </button>

            @include('cloud::marketing.home.partials.hero-demo-modal')
        @endif
    </x-slot:aside>

    <div class="border-rail border-t">
        <div id="hero-tabs" role="tablist" class="divide-rail border-rail relative z-10 grid grid-cols-4 divide-x border-b">
            <div x-ref="indicator" class="pointer-events-none !border-0 absolute bottom-0 h-0.5 bg-primary transition-[left,width] duration-200" aria-hidden="true"></div>
                    <button type="button" id="tab-ai-agent" role="tab" :aria-selected="(activeTab === 'ai-agent').toString()" :tabindex="activeTab === 'ai-agent' ? 0 : -1" aria-controls="panel-ai-agent" x-ref="tab-ai-agent" x-on:click="switchTab('ai-agent')" x-on:keydown="handleTabKeydown($event)" :class="tabClasses('ai-agent')" class="relative py-3.5 text-xs font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer sm:py-4 sm:text-sm">
                        <span class="sm:hidden">{{ __('Ask') }}</span><span class="hidden sm:inline">{{ __('Ask :name', ['name' => (string) config('chat.assistant_name')]) }}</span>
                    </button>
                    <button type="button" id="tab-pipeline" role="tab" :aria-selected="(activeTab === 'pipeline').toString()" :tabindex="activeTab === 'pipeline' ? 0 : -1" aria-controls="panel-pipeline" x-ref="tab-pipeline" x-on:click="switchTab('pipeline')" x-on:keydown="handleTabKeydown($event)" :class="tabClasses('pipeline')" class="relative py-3.5 text-xs font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer sm:py-4 sm:text-sm">
                        Pipeline
                    </button>
                    <button type="button" id="tab-companies" role="tab" :aria-selected="(activeTab === 'companies').toString()" :tabindex="activeTab === 'companies' ? 0 : -1" aria-controls="panel-companies" x-ref="tab-companies" x-on:click="switchTab('companies')" x-on:keydown="handleTabKeydown($event)" :class="tabClasses('companies')" class="relative py-3.5 text-xs font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer sm:py-4 sm:text-sm">
                        Companies
                    </button>
                    <button type="button" id="tab-custom-fields" role="tab" :aria-selected="(activeTab === 'custom-fields').toString()" :tabindex="activeTab === 'custom-fields' ? 0 : -1" aria-controls="panel-custom-fields" x-ref="tab-custom-fields" x-on:click="switchTab('custom-fields')" x-on:keydown="handleTabKeydown($event)" :class="tabClasses('custom-fields')" class="relative py-3.5 text-xs font-medium whitespace-nowrap transition-colors duration-200 cursor-pointer sm:py-4 sm:text-sm">
                        <span class="sm:hidden">Fields</span><span class="hidden sm:inline">Custom Fields</span>
                    </button>
        </div>

        <div class="bg-tint px-3 py-6 sm:px-8 md:py-12">
            <div class="relative mx-auto max-w-4xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04),0_24px_48px_-24px_rgba(0,0,0,0.18)] dark:border-white/[0.1] dark:bg-neutral-950 dark:shadow-none">
                {{-- Browser chrome --}}
                <div class="bg-gray-50 dark:bg-neutral-900 border-b border-gray-200/60 dark:border-white/[0.06] px-4 py-2.5 flex items-center justify-between">
                    <div class="flex gap-1.5">
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                        <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                    </div>
                    <div class="bg-white/60 dark:bg-white/[0.04] rounded-md px-3 py-1 text-[11px] text-gray-500 dark:text-gray-500">
                        app.relaticle.com
                    </div>
                </div>

                {{-- Tab panels use grid stacking for a Safari-smooth crossfade.
                     min-height matches the live chat panel so switching to an
                     image tab can't collapse the mockup frame.

                     The image tabs FILL that frame (object-cover) rather than
                     sitting in it at their own aspect: the frame is a constant
                     826x640 from `lg` up, so the screenshots are captured at
                     that ratio and land pixel-exact there. Below `lg` the frame
                     narrows and finally turns portrait, and cover crops the
                     right edge instead of leaving the dead band under the
                     image that a plain `h-auto` used to (measured: 124px at
                     desktop, over half the frame on a phone). object-left-top
                     keeps the crop predictable -- you always see the app from
                     its top-left corner, which is what the alt text
                     describes. --}}
                <div class="relative grid overflow-hidden min-h-[520px] sm:min-h-[580px] md:min-h-[640px]">
                    {{-- AI Agent tab (default, featured) --}}
                    {{-- min-w-0: a grid item defaults to min-width:auto, so the
                         chat panel's own table would size the column to its
                         intrinsic width and push the transcript past the frame. --}}
                    <div id="panel-ai-agent" role="tabpanel" aria-labelledby="tab-ai-agent" x-ref="panel-ai-agent" class="col-start-1 row-start-1 min-w-0">
                        @include('cloud::marketing.home.partials.hero-agent-preview')
                    </div>

                    @php
                        $heroScreenshots = [
                            'pipeline' => ['widths' => [380, 640, 832, 1652], 'alt' => __('Relaticle opportunities board grouped into pipeline stages, showing amount and close date')],
                            'companies' => ['widths' => [1652], 'alt' => __('Relaticle companies list showing account owner, ICP status, and website domain for each company')],
                            'custom-fields' => ['widths' => [1652], 'alt' => __('Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities')],
                        ];

                        // 1652w is rendered at the frame's own pixel size on a 2x display, so the browser draws it without resampling.
                        $heroSrcset = static fn (string $id, string $theme, array $widths): string => collect($widths)
                            ->map(fn (int $width): string => Vite::asset("resources/images/app-{$id}-preview{$theme}-{$width}w.webp")." {$width}w")
                            ->push(Vite::asset("resources/images/app-{$id}-preview{$theme}.webp").' 2880w')
                            ->implode(', ');
                    @endphp

                    @foreach($heroScreenshots as $id => $screenshot)
                        <div id="panel-{{ $id }}" role="tabpanel" aria-labelledby="tab-{{ $id }}" x-ref="panel-{{ $id }}" class="col-start-1 row-start-1 invisible absolute inset-0 w-full">
                            <picture class="block h-full w-full">
                                <source data-light-srcset="{{ $heroSrcset($id, '', $screenshot['widths']) }}"
                                        data-dark-srcset="{{ $heroSrcset($id, '-dark', $screenshot['widths']) }}"
                                        srcset="{{ $heroSrcset($id, '', $screenshot['widths']) }}"
                                        sizes="(max-width: 640px) 750px, (max-width: 1023px) 842px, 826px"
                                        type="image/webp">
                                <img data-light-src="{{ Vite::asset("resources/images/app-{$id}-preview.png") }}"
                                     data-dark-src="{{ Vite::asset("resources/images/app-{$id}-preview-dark.png") }}"
                                     src="{{ Vite::asset("resources/images/app-{$id}-preview.png") }}"
                                     alt="{{ $screenshot['alt'] }}"
                                     class="hero-preview-image h-full w-full object-cover object-left-top"
                                     width="1440"
                                     height="1116"
                                     loading="lazy">
                            </picture>
                        </div>
                    @endforeach
                    {{-- Bottom fade overlay --}}
                    <div class="absolute bottom-0 left-0 right-0 h-24 bg-gradient-to-t from-white/60 via-white/20 to-transparent dark:from-gray-950/60 dark:via-gray-950/20 dark:to-transparent pointer-events-none"></div>
                </div>
            </div>
        </div>
    </div>
</x-cloud::marketing.hero>

<script>
    function heroTabs() {
        return {
            activeTab: 'ai-agent',
            tabOrder: ['ai-agent', 'pipeline', 'companies', 'custom-fields'],
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ease: [0.16, 1, 0.3, 1],
            duration: 0.35,
            slideDistance: 40,
            githubStarsAnimationState: 'idle',
            githubStarsAnimationRequestedAt: null,

            init() {
                this.positionIndicator();
                this.updateImages();
                this.observeDarkMode();
                this.animateGithubStars();
                if (this.activeTab === 'ai-agent') {
                    var self = this;
                    setTimeout(function() { self.$dispatch('hero-chat-animate'); }, 50);
                }
            },

            animateGithubStars() {
                if (this.githubStarsAnimationState === 'complete') return;

                var element = this.$refs.githubStars;
                if (!element) {
                    this.githubStarsAnimationState = 'complete';
                    return;
                }

                if (this.githubStarsAnimationRequestedAt === null) {
                    this.githubStarsAnimationRequestedAt = performance.now();
                }

                if (this.reducedMotion || document.visibilityState !== 'visible') {
                    this.githubStarsAnimationState = 'complete';
                    return;
                }

                if (typeof window.animate !== 'function') {
                    if (this.githubStarsAnimationState === 'waiting') return;

                    this.githubStarsAnimationState = 'waiting';
                    window.addEventListener('motion-ready', () => {
                        if (performance.now() - this.githubStarsAnimationRequestedAt > 250) {
                            this.githubStarsAnimationState = 'complete';
                            return;
                        }

                        this.githubStarsAnimationState = 'idle';
                        this.animateGithubStars();
                    }, { once: true });
                    return;
                }

                this.githubStarsAnimationState = 'complete';
                element.querySelectorAll('[data-github-star-digit]').forEach(function(reel, index) {
                    var digit = Number(reel.dataset.githubStarDigit);
                    if (digit === 0) return;

                    window.animate(reel, {
                        transform: ['translateY(0em)', 'translateY(-' + digit + 'em)'],
                    }, {
                        type: window.spring,
                        visualDuration: 0.48,
                        bounce: 0,
                        delay: index * 0.03,
                    });
                });
            },

            positionIndicator() {
                var tab = this.$refs['tab-' + this.activeTab];
                var indicator = this.$refs.indicator;
                if (!tab || !indicator) return;
                indicator.style.left = tab.offsetLeft + 'px';
                indicator.style.width = tab.offsetWidth + 'px';
            },

            showPanel(panel) {
                panel.classList.remove('invisible', 'absolute', 'inset-0');
                panel.style.position = 'relative';
                panel.style.zIndex = '1';
            },

            hidePanel(panel) {
                panel.classList.add('invisible', 'absolute', 'inset-0');
                panel.style.position = '';
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

            handleTabKeydown(event) {
                var key = event.key;
                var index = this.tabOrder.indexOf(this.activeTab);
                var next = -1;

                if (key === 'ArrowRight') {
                    next = (index + 1) % this.tabOrder.length;
                } else if (key === 'ArrowLeft') {
                    next = (index - 1 + this.tabOrder.length) % this.tabOrder.length;
                } else if (key === 'Home') {
                    next = 0;
                } else if (key === 'End') {
                    next = this.tabOrder.length - 1;
                }

                if (next >= 0) {
                    event.preventDefault();
                    this.switchTab(this.tabOrder[next]);
                    this.$refs['tab-' + this.tabOrder[next]].focus();
                }
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
