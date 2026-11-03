<section x-data="heroTabs()" x-init="init()" class="bg-surface relative overflow-hidden pt-16">
    <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(0,0,0,0.015)_1px,transparent_1px),linear-gradient(to_bottom,rgba(0,0,0,0.015)_1px,transparent_1px)] dark:bg-[linear-gradient(to_right,rgba(255,255,255,0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.025)_1px,transparent_1px)] bg-[size:3rem_3rem] [mask-image:radial-gradient(ellipse_70%_50%_at_50%_50%,black_30%,transparent_100%)]" aria-hidden="true"></div>

    <div class="border-rail relative mx-auto max-w-6xl sm:border-x">
        <div class="flex flex-col items-center px-6 pb-14 pt-16 text-center md:px-10 md:pb-16 md:pt-24">
            <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener"
               aria-label="{{ __('Open Source, :count+ GitHub stars', ['count' => $formattedGithubStars]) }}"
               class="group inline-flex items-center gap-2 rounded-full border border-gray-200/80 dark:border-white/[0.08] bg-white/80 dark:bg-white/[0.04] backdrop-blur-sm px-4 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 shadow-[0_1px_2px_rgba(0,0,0,0.04)] transition-all duration-200 hover:border-gray-300 dark:hover:border-white/[0.15]">
                <x-ri-github-fill class="h-3.5 w-3.5"/>
                <span>{{ __('Open Source') }}</span>
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

            <h1 class="type-display mt-8 max-w-3xl md:mt-10">
                <span class="block font-normal text-gray-500">{{ __('The AI CRM for selling') }}</span>
                <span class="block text-gray-950 dark:text-white">{{ __('without a sales team') }}</span>
            </h1>

            <p class="mt-6 max-w-xl text-base leading-relaxed text-gray-500 sm:text-lg dark:text-gray-400">
                {{ __('For people who sell to a named list of companies.') }}<br class="hidden sm:block"/>
                {{ __('Work from Claude or ChatGPT, or ask :name, the built-in AI assistant.', ['name' => config('chat.assistant_name')]) }}
            </p>

            <div class="mt-8 flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                <x-cloud::marketing.button href="{{ route('login') }}" data-analytics-intent="signup" data-analytics-placement="hero">
                    {{ __('Start for free') }}
                </x-cloud::marketing.button>

                <x-cloud::marketing.button variant="secondary" href="{{ route('contact') }}">
                    {{ __('Get in touch') }}
                </x-cloud::marketing.button>
            </div>

            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __(':days-day trial, no card, unlimited users.', ['days' => \App\Models\Workspace::PRO_TRIAL_DAYS]) }}</p>

            @if(file_exists(public_path('videos/hero-demo.mp4')))
                <button
                    type="button"
                    x-data
                    @click="$dispatch('open-hero-demo')"
                    class="mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 transition-colors hover:text-gray-950 dark:text-gray-400 dark:hover:text-white"
                >
                    <x-ri-play-circle-line class="h-4 w-4"/>
                    {{ __('Watch 30s demo') }}
                    <x-ri-arrow-right-up-line class="h-3 w-3"/>
                </button>

                @include('cloud::marketing.home.partials.hero-demo-modal')
            @endif

        </div>

        @php
            $heroTabs = [
                'ai-agent' => [__('Ask'), __('Ask :name', ['name' => (string) config('chat.assistant_name')])],
                'pipeline' => [__('Pipeline'), __('Pipeline')],
                'companies' => [__('Companies'), __('Companies')],
                'custom-fields' => [__('Fields'), __('Custom fields')],
            ];
        @endphp

        <div id="hero-tabs" role="tablist" class="border-rail divide-rail grid grid-cols-4 divide-x border-t">
            @foreach($heroTabs as $id => [$short, $long])
                <button type="button"
                        id="tab-{{ $id }}"
                        role="tab"
                        :aria-selected="(activeTab === '{{ $id }}').toString()"
                        :tabindex="activeTab === '{{ $id }}' ? 0 : -1"
                        aria-controls="panel-{{ $id }}"
                        x-ref="tab-{{ $id }}"
                        x-on:click="switchTab('{{ $id }}')"
                        x-on:keydown="handleTabKeydown($event)"
                        :class="activeTab === '{{ $id }}' ? 'bg-tint/70 text-gray-950 dark:text-white' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="relative cursor-pointer py-3.5 text-xs font-medium transition-colors sm:text-sm">
                    <span class="sm:hidden">{{ $short }}</span><span class="hidden sm:inline">{{ $long }}</span>
                    <span class="absolute inset-x-0 bottom-0 h-px bg-primary" x-show="activeTab === '{{ $id }}'" aria-hidden="true"></span>
                </button>
            @endforeach
        </div>

        <div class="bg-tint/60 border-rail relative border-t p-6 sm:p-8 md:p-10">

            <div class="rounded-2xl border border-gray-200 bg-gray-100 p-1.5 pt-0 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_16px_40px_-16px_rgba(0,0,0,0.14)] dark:border-white/[0.1] dark:bg-gray-900 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.06),0_16px_40px_-16px_rgba(0,0,0,0.6)]">
                <div class="flex h-9 items-center gap-1.5 px-2.5" aria-hidden="true">
                    <span class="size-3 rounded-full bg-[#ff5c5a]"></span>
                    <span class="size-3 rounded-full bg-[#f5b902]"></span>
                    <span class="size-3 rounded-full bg-[#10c27b]"></span>
                </div>

                <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/[0.06] dark:bg-gray-950">
                    {{-- The frame keeps the chat panel's height on every tab, so the screenshots cover it from
                         their top-left corner and crop the rest instead of resizing the frame. --}}
                    <div class="relative h-[520px] sm:h-[580px] md:h-[640px]">
                        <div id="panel-ai-agent" role="tabpanel" aria-labelledby="tab-ai-agent" x-ref="panel-ai-agent" class="absolute inset-0">
                            @include('cloud::marketing.home.partials.hero-agent-preview')
                        </div>

                        @php
                            $heroScreenshots = [
                                'pipeline' => ['widths' => [380, 640, 832, 1652], 'alt' => __('Relaticle opportunities board grouped into pipeline stages, showing amount and close date')],
                                'companies' => ['widths' => [1652], 'alt' => __('Relaticle companies list showing account owner, ICP status, and website domain for each company')],
                                'custom-fields' => ['widths' => [1652], 'alt' => __('Relaticle custom fields settings showing field name, type, constraints, and properties for Opportunities')],
                            ];

                            // 1652w fills the 1056px desktop frame at 1x; a 2x display takes the 2880w original.
                            $heroSrcset = static fn (string $id, string $theme, array $widths): string => collect($widths)
                                ->map(fn (int $width): string => Vite::asset("resources/images/app-{$id}-preview{$theme}-{$width}w.webp")." {$width}w")
                                ->push(Vite::asset("resources/images/app-{$id}-preview{$theme}.webp").' 2880w')
                                ->implode(', ');
                        @endphp

                        @foreach($heroScreenshots as $id => $screenshot)
                            <div id="panel-{{ $id }}" role="tabpanel" aria-labelledby="tab-{{ $id }}" x-ref="panel-{{ $id }}" class="invisible absolute inset-0">
                                <picture class="block h-full w-full">
                                    <source data-light-srcset="{{ $heroSrcset($id, '', $screenshot['widths']) }}"
                                            data-dark-srcset="{{ $heroSrcset($id, '-dark', $screenshot['widths']) }}"
                                            srcset="{{ $heroSrcset($id, '', $screenshot['widths']) }}"
                                            sizes="(max-width: 640px) 750px, (max-width: 1151px) calc(100vw - 96px), 1056px"
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
    </div>
</section>

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

                if (previousTab === 'ai-agent') {
                    this.$dispatch('hero-chat-stop');
                }

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
