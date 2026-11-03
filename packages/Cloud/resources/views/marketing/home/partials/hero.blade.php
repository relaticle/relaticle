<section x-data="heroTabs()" x-init="init()" @hero-chat-state.window="chatState = $event.detail" class="bg-surface pt-16">
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        <div class="flex flex-col items-center px-6 pb-14 pt-16 text-center md:px-10 md:pb-16 md:pt-24">
            <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener"
               aria-label="{{ __('Open Source, :count+ GitHub stars', ['count' => $formattedGithubStars]) }}"
               class="type-label border-rail inline-flex items-center gap-2 rounded-full border py-1 pl-3 pr-2.5 text-gray-500 transition-colors hover:border-gray-300 hover:text-gray-950 dark:text-gray-400 dark:hover:border-white/20 dark:hover:text-white">
                <x-ri-github-fill class="h-3.5 w-3.5"/>
                {{ __('Open source') }}
                <span class="bg-rail h-3 w-px" aria-hidden="true"></span>
                <span class="whitespace-nowrap text-gray-950 dark:text-white">{{ __(':count+ stars', ['count' => $formattedGithubStars]) }}</span>
                <x-ri-arrow-right-up-line class="h-3 w-3"/>
            </a>

            <h1 class="type-display mt-8 max-w-3xl">
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

        <div id="hero-tabs" role="tablist" class="bg-rail border-rail grid grid-cols-4 gap-px border-t">
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
                        :class="activeTab === '{{ $id }}' ? 'bg-tint text-gray-950 dark:text-white' : 'bg-surface text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="relative cursor-pointer py-3.5 text-xs font-medium transition-colors sm:text-sm">
                    <span class="sm:hidden">{{ $short }}</span><span class="hidden sm:inline">{{ $long }}</span>
                    <span class="absolute inset-x-0 bottom-0 h-px bg-primary" x-show="activeTab === '{{ $id }}'" aria-hidden="true"></span>
                </button>
            @endforeach
        </div>

        <div class="bg-tint border-rail relative border-t px-6 pb-6 pt-14 sm:px-8 sm:pb-8 md:px-10 md:pb-10">
            <x-cloud::marketing.eyebrow class="pointer-events-none absolute left-6 top-5 z-10" x-text="'{{ __('Fig 00') }} · ' + tabLabels[activeTab]">{{ __('Fig 00') }} · {{ $heroTabs['ai-agent'][1] }}</x-cloud::marketing.eyebrow>

            <div class="mx-auto w-full max-w-[52rem]">
                <x-cloud::marketing.window class="relative">
                    {{-- Browser chrome --}}
                        <div class="bg-gray-50 dark:bg-neutral-900 border-b border-gray-200/60 dark:border-white/[0.06] px-4 py-2.5 flex items-center justify-between">
                            <div class="flex gap-1.5">
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                                <div class="w-2.5 h-2.5 rounded-full bg-gray-300/80 dark:bg-white/[0.08]"></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="bg-white/60 dark:bg-white/[0.04] rounded-md px-3 py-1 text-[11px] text-gray-500 dark:text-gray-400">
                                    app.relaticle.com
                                </div>
                                <button type="button"
                                        x-cloak
                                        x-show="activeTab === 'ai-agent' && !reducedMotion"
                                        @click="$dispatch(chatState === 'done' ? 'hero-chat-animate' : 'hero-chat-skip')"
                                        :aria-label="chatState === 'done' ? @js(__('Replay the demo')) : @js(__('Skip to the end of the demo'))"
                                        class="inline-flex h-6 items-center gap-1 rounded-md px-1.5 text-[11px] font-medium text-gray-500 transition-colors hover:bg-black/[0.04] hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white">
                                    <x-ri-restart-line x-show="chatState === 'done'" class="h-3.5 w-3.5"/>
                                    <x-ri-skip-forward-line x-show="chatState !== 'done'" class="h-3.5 w-3.5"/>
                                    <span x-text="chatState === 'done' ? @js(__('Replay')) : @js(__('Skip'))"></span>
                                </button>
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
                </x-cloud::marketing.window>
            </div>
        </div>
    </div>
</section>

<script>
    function heroTabs() {
        return {
            activeTab: 'ai-agent',
            tabOrder: ['ai-agent', 'pipeline', 'companies', 'custom-fields'],
            tabLabels: @js(array_map(static fn (array $labels): string => $labels[1], $heroTabs)),
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            ease: [0.16, 1, 0.3, 1],
            duration: 0.35,
            slideDistance: 40,
            chatState: 'playing',

            init() {
                this.updateImages();
                this.observeDarkMode();
                if (this.activeTab === 'ai-agent') {
                    var self = this;
                    setTimeout(function() { self.$dispatch('hero-chat-animate'); }, 50);
                }
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
