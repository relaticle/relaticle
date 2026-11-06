<style>
    .hero-agent-preview .mcp-el { opacity: 0; }
    .hero-agent-preview,
    .hero-agent-preview * {
        user-select: none;
        -webkit-user-select: none;
        -webkit-user-drag: none;
    }
    .hero-agent-preview .overflow-y-auto {
        scrollbar-width: none;
        -ms-overflow-style: none;
        -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 28px, #000 calc(100% - 20px), transparent 100%);
                mask-image: linear-gradient(to bottom, transparent 0, #000 28px, #000 calc(100% - 20px), transparent 100%);
    }
    .hero-agent-preview .overflow-y-auto::-webkit-scrollbar {
        display: none;
    }
    .hero-agent-preview .hero-agent-title { opacity: 0; }
</style>

<div x-data="heroChat()"
     @hero-chat-reset.window="reset()"
     @hero-chat-stop.window="stop()"
     @hero-chat-animate.window="play()"
     data-hero-demo
     data-demo-state="idle"
     class="hero-agent-preview relative bg-white dark:bg-zinc-900 flex h-[520px] sm:h-[580px] md:h-[640px]">

    <div aria-hidden="true"
         class="absolute inset-0 z-30 cursor-default"
         @contextmenu.prevent></div>

    @include('cloud::marketing.home.partials.hero-agent-shell')

    <div class="relative flex-1 flex flex-col min-w-0">

        {{-- Mirrors the real fi-topbar: a chat page carries its heading here and has no in-body title. --}}
        <div class="relative z-30 flex h-10 shrink-0 items-center justify-between border-b border-zinc-200/60 bg-white px-3 dark:border-white/10 dark:bg-zinc-900">
            <div class="hero-agent-title min-w-0 flex-1">
                <h2 class="truncate text-sm font-semibold text-gray-900 dark:text-white">Overdue tasks this week</h2>
            </div>
            <img src="{{ resolve(\App\Services\AvatarService::class)->generateAuto('Marcus Reid', 64) }}" alt="" class="h-6 w-6 shrink-0 rounded-full">
        </div>

        <div x-ref="messagesScroll" class="flex-1 overflow-y-auto p-4 sm:p-6 md:px-8 md:py-6">
            <div class="mx-auto w-full max-w-3xl space-y-5 sm:space-y-6">
                @include('cloud::marketing.home.partials.hero-agent-conversation')
            </div>
        </div>

        @include('cloud::marketing.home.partials.hero-agent-composer')

        @include('cloud::marketing.home.partials.hero-agent-entry')

    </div>

</div>

<script>
    function heroChat() {
        const ease = [0.22, 1, 0.36, 1];
        const glide = [0.32, 0.72, 0, 1];
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        let controller = null;
        let followFrame = null;
        let followPosition = 0;
        let followed = null;
        const running = new Set();

        class Cancelled extends Error {}

        return {
            prompts: [
                { text: "What's overdue this week?", charMs: 46 },
                { text: 'Mark the Kovra demo as done.', charMs: 36 },
                { text: "Add Sarah Chen at @Kovra Systems. She's VP of Engineering.", charMs: 26 },
            ],

            find(selector) {
                return this.$root.querySelector(selector);
            },

            findAll(selector) {
                return [...this.$root.querySelectorAll(selector)];
            },

            setState(state) {
                this.$root.dataset.demoState = state;
            },

            play() {
                this.stop();
                this.reset();

                if (reducedMotion) {
                    this.showFinal();
                    this.setState('done');

                    return;
                }

                if (typeof animate !== 'function') {
                    window.addEventListener('motion-ready', () => this.play(), { once: true });

                    return;
                }

                controller = new AbortController();
                const signal = controller.signal;

                this.setState('playing');
                this.startFollow();

                this.cycle(this.stepper(signal), this.waiter(signal))
                    .then(() => {
                        this.stopFollow();
                        this.setState('done');
                    })
                    .catch((error) => {
                        if (!(error instanceof Cancelled)) {
                            throw error;
                        }
                    });
            },

            stop() {
                controller?.abort();
                controller = null;
                running.forEach((controls) => controls.stop());
                running.clear();
                this.stopFollow();

                if (this.$root.dataset.demoState === 'playing') {
                    this.setState('idle');
                }
            },

            stepper(signal) {
                return async (controls) => {
                    running.add(controls);

                    await Promise.race([
                        controls,
                        new Promise((resolve) => signal.addEventListener('abort', resolve, { once: true })),
                    ]);

                    running.delete(controls);

                    if (signal.aborted) {
                        throw new Cancelled();
                    }
                };
            },

            track(controls) {
                running.add(controls);
                controls.then(() => running.delete(controls));
            },

            waiter(signal) {
                return (ms) => new Promise((resolve, reject) => {
                    if (signal.aborted) {
                        reject(new Cancelled());

                        return;
                    }

                    const timer = setTimeout(resolve, ms);

                    signal.addEventListener('abort', () => {
                        clearTimeout(timer);
                        reject(new Cancelled());
                    }, { once: true });
                });
            },

            reset() {
                this.stop();

                this.findAll('.mcp-el, .mcp-entry, .mcp-entry-composer > div, .mcp-entry-composer > div > *, .hero-agent-entry, .hero-entry-bg, .mcp-dock, .mcp-input, .hero-agent-title').forEach((element) => {
                    element.getAnimations().forEach((animation) => animation.cancel());
                    element.style.opacity = '';
                    element.style.transform = '';
                    element.style.visibility = '';
                });

                this.findAll('[data-stream-source]').forEach((element) => {
                    element.textContent = element.dataset.streamSource;
                });

                this.setFooterLayer('input');
                this.$refs.composerFooter.style.height = '';
                this.clearComposer('entry');
                this.clearComposer('composer');
                this.setShellActive('home');

                followed = null;
                followPosition = 0;
                this.$refs.messagesScroll.scrollTop = 0;

                this.setState('idle');
            },

            showFinal() {
                this.findAll('.mcp-el').forEach((element) => {
                    element.style.opacity = '1';
                });
                this.findAll('.mcp-tool').forEach((element) => {
                    element.style.opacity = '0';
                });
                this.find('.hero-agent-title').style.opacity = '1';
                this.find('.hero-agent-entry').style.visibility = 'hidden';
                this.setShellActive('chat');

                const scroller = this.$refs.messagesScroll;
                scroller.scrollTop = scroller.scrollHeight;
            },

            async cycle(step, wait) {
                const [first, second, third] = this.prompts;

                await wait(700);
                await this.typePrompt('entry', first, wait);
                await wait(260);
                await this.send('entry', step);
                await this.enterConversation(step);
                await this.answer(step, wait, 1);
                await this.revealRows(step);

                await wait(800);
                await this.typePrompt('composer', second, wait);
                await wait(220);
                await this.send('composer', step);
                await this.reveal(step, '.mcp-user-2', { y: 14, duration: 0.4 });
                await this.answer(step, wait, 2);
                await this.review(step, wait, 'update', ['.mcp-audit-card', '.mcp-approve-done']);

                await wait(700);
                await this.typePrompt('composer', third, wait);
                await wait(220);
                await this.send('composer', step);
                await this.reveal(step, '.mcp-user-3', { y: 14, duration: 0.4 });
                await this.answer(step, wait, 3);
                await this.review(step, wait, 'create', ['.mcp-create-card', '.mcp-create-done', '.mcp-card']);

                await wait(500);
                await this.reveal(step, '.mcp-next-steps', { y: 6 });
                await wait(400);
            },

            reveal(step, selector, { y = 8, duration = 0.35, delay = 0 } = {}) {
                const element = this.find(selector);
                followed = element;

                return step(animate(element, { opacity: [0, 1], transform: [`translateY(${y}px)`, 'none'] }, { duration, delay, ease }));
            },

            async revealRows(step) {
                await this.reveal(step, '.mcp-tasks-table', { y: 6, duration: 0.3 });

                await Promise.all(this.findAll('.mcp-task-card').map((row, index) => {
                    followed = this.find('.mcp-tasks-table');

                    return step(animate(row, { opacity: [0, 1], transform: ['translateY(6px)', 'none'] }, { duration: 0.3, delay: index * 0.09, ease }));
                }));
            },

            async answer(step, wait, exchange) {
                const turn = this.find(`.mcp-avatar-${exchange}`);
                turn.style.opacity = '1';
                followed = turn;

                await wait(180);
                await step(animate(this.find(`.mcp-tool-${exchange}`), { opacity: [0, 1], transform: ['translateY(4px)', 'none'] }, { duration: 0.25, ease }));
                await wait(exchange === 1 ? 700 : 500);

                this.track(animate(this.find(`.mcp-tool-${exchange}`), { opacity: [1, 0] }, { duration: 0.16, ease }));
                await wait(140);
                await this.stream(wait, this.find(`.mcp-text-${exchange}`));
            },

            async stream(wait, element) {
                element.dataset.streamSource ??= element.textContent.trim();

                const words = element.dataset.streamSource.split(/\s+/);
                element.textContent = '';
                element.style.opacity = '1';

                const spans = words.map((word, index) => {
                    const span = document.createElement('span');
                    span.textContent = (index === 0 ? '' : ' ') + word;
                    span.style.opacity = '0';
                    element.appendChild(span);

                    return span;
                });

                for (const span of spans) {
                    this.track(animate(span, { opacity: [0, 1], transform: ['translateY(3px)', 'none'] }, { duration: 0.22, ease }));
                    await wait(42 + Math.random() * 40);
                }

                await wait(220);
            },

            async typePrompt(composer, prompt, wait) {
                const typed = this.find(`#hero-${composer}-typed`);

                this.find(`#hero-${composer}-placeholder`).classList.add('is-hidden');
                this.setSendActive(composer, true);

                for (const character of prompt.text) {
                    typed.textContent += character;

                    const pause = character === ' ' ? 18 : 0;
                    const beat = /[.,?]/.test(character) ? 90 : 0;

                    await wait(prompt.charMs * (0.6 + Math.random() * 0.8) + pause + beat);
                }
            },

            async send(composer, step) {
                const button = this.find(`#hero-${composer}-send`);

                await step(animate(button, { transform: ['scale(1)', 'scale(0.88)', 'scale(1)'] }, { duration: 0.24, ease }));
                this.clearComposer(composer);
            },

            async enterConversation(step) {
                const entryBox = this.find('.mcp-entry-composer > div');
                const inputBox = this.find('.mcp-input > div');
                const input = this.find('.mcp-input');
                const from = entryBox.getBoundingClientRect();
                const to = inputBox.getBoundingClientRect();
                const morph = `translate(${to.left + to.width / 2 - (from.left + from.width / 2)}px, ${to.top - from.top}px) scale(${to.width / from.width}, ${to.height / from.height})`;

                entryBox.style.transformOrigin = 'top center';
                this.setShellActive('chat');

                await Promise.all([
                    step(animate(this.findAll('.mcp-entry:not(.mcp-entry-composer)'), { opacity: [1, 0], transform: ['none', 'translateY(-10px)'] }, { duration: 0.28, ease })),
                    step(animate([...entryBox.children], { opacity: [1, 0] }, { duration: 0.14 })),
                    step(animate(entryBox, { transform: ['none', morph] }, { duration: 0.6, ease: glide })),
                    step(animate(entryBox, { opacity: [1, 0] }, { duration: 0.14, delay: 0.56 })),
                    step(animate(input, { opacity: [0, 1] }, { duration: 0.14, delay: 0.52 })),
                    step(animate(this.find('.hero-entry-bg'), { opacity: [1, 0] }, { duration: 0.4, delay: 0.1, ease })),
                    step(animate(this.find('.hero-agent-title'), { opacity: [0, 1], transform: ['translateY(-4px)', 'none'] }, { duration: 0.4, delay: 0.22, ease })),
                    this.reveal(step, '.mcp-user-1', { y: 14, duration: 0.4, delay: 0.5 }),
                ]);

                input.style.opacity = '1';
                this.find('.hero-agent-title').style.opacity = '1';
                this.find('.hero-agent-entry').style.visibility = 'hidden';
            },

            async review(step, wait, variant, decided) {
                this.setDockVariant(variant);
                await this.swapFooter(step, 'dock');
                await wait(1100);

                await step(animate(this.find('#hero-approve-btn'), { transform: ['scale(1)', 'scale(0.94)', 'scale(1)'] }, { duration: 0.26, ease }));
                await this.swapFooter(step, 'input');

                await Promise.all(decided.map((selector, index) => this.reveal(step, selector, { y: 8, duration: 0.35, delay: 0.1 + index * 0.14 })));
            },

            async swapFooter(step, layer) {
                const footer = this.$refs.composerFooter;
                const showing = this.find(layer === 'dock' ? '.mcp-dock' : '.mcp-input');
                const hiding = this.find(layer === 'dock' ? '.mcp-input' : '.mcp-dock');
                const from = footer.getBoundingClientRect().height;

                this.setFooterLayer(layer, { keepVisible: hiding });

                const to = footer.getBoundingClientRect().height;
                footer.style.height = `${from}px`;

                await Promise.all([
                    step(animate(footer, { height: [`${from}px`, `${to}px`] }, { duration: 0.45, ease: glide })),
                    step(animate(hiding, { opacity: [1, 0] }, { duration: 0.16, ease })),
                    step(animate(showing, { opacity: [0, 1], transform: ['translateY(10px)', 'none'] }, { duration: 0.38, delay: 0.08, ease })),
                ]);

                footer.style.height = '';
                hiding.style.opacity = '0';
                showing.style.opacity = '1';
                this.setFooterLayer(layer);
            },

            setFooterLayer(layer, { keepVisible = null } = {}) {
                const active = this.find(layer === 'dock' ? '.mcp-dock' : '.mcp-input');
                const inactive = this.find(layer === 'dock' ? '.mcp-input' : '.mcp-dock');

                active.classList.remove('absolute', 'inset-x-0', 'bottom-0', 'invisible');
                inactive.classList.add('absolute', 'inset-x-0', 'bottom-0');
                inactive.classList.toggle('invisible', inactive !== keepVisible);
            },

            setDockVariant(variant) {
                const isCreate = variant === 'create';

                this.find('.mcp-dock-update').classList.toggle('hidden', isCreate);
                this.find('.mcp-dock-create').classList.toggle('hidden', !isCreate);
                this.find('.mcp-dock-label').textContent = isCreate ? 'Create' : 'Save changes';
            },

            setSendActive(composer, active) {
                const button = this.find(`#hero-${composer}-send`);

                button.classList.toggle('bg-primary-600', active);
                button.classList.toggle('text-white', active);
                ['bg-gray-200', 'text-gray-400', 'dark:bg-gray-700', 'dark:text-gray-500'].forEach((token) => button.classList.toggle(token, !active));
            },

            clearComposer(composer) {
                this.find(`#hero-${composer}-typed`).textContent = '';
                this.find(`#hero-${composer}-placeholder`).classList.remove('is-hidden');
                this.setSendActive(composer, false);
            },

            setShellActive(which) {
                this.find('#hero-shell-nav-home').toggleAttribute('data-active', which === 'home');
                this.find('#hero-shell-nav-chat').toggleAttribute('data-active', which === 'chat');
            },

            startFollow() {
                const scroller = this.$refs.messagesScroll;
                let previous = performance.now();

                const tick = (now) => {
                    const elapsed = now - previous;
                    previous = now;

                    if (followed) {
                        const bottom = followed.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + scroller.scrollTop;
                        const limit = scroller.scrollHeight - scroller.clientHeight;
                        const target = Math.min(limit, Math.max(0, bottom + 28 - scroller.clientHeight));

                        if (target > followPosition) {
                            followPosition += (target - followPosition) * (1 - Math.exp(-elapsed / 150));
                            scroller.scrollTop = followPosition;
                        }
                    }

                    followFrame = requestAnimationFrame(tick);
                };

                followFrame = requestAnimationFrame(tick);
            },

            stopFollow() {
                cancelAnimationFrame(followFrame);
                followFrame = null;
            },
        };
    }
</script>
