<style>
    .hero-agent-preview .mcp-el { opacity: 0; }
    .hero-agent-preview,
    .hero-agent-preview * {
        user-select: none;
        -webkit-user-select: none;
        -webkit-user-drag: none;
    }
    /* Hide scrollbar — panel reads as a video preview, scrollbar would break the illusion */
    .hero-agent-preview .overflow-y-auto {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .hero-agent-preview .overflow-y-auto::-webkit-scrollbar {
        display: none;
    }
</style>

<div x-data="heroChat()"
     @hero-chat-reset.window="cancelInflight(); resetChat()"
     @hero-chat-animate.window="animateChat()"
     @mouseenter="pause()"
     @mouseleave="resume()"
     @focusin="pause()"
     @focusout="resume()"
     class="hero-agent-preview relative bg-gray-50 dark:bg-gray-950 flex h-[520px] sm:h-[580px] md:h-[640px]">

    {{-- Non-interactive overlay: blocks clicks, right-click, drag.
         Mouseenter/leave on the root still fire — they trigger from cursor
         crossing the bounding rect, not from event dispatch on a specific child.
         z-30 puts it above all panel content. --}}
    <div aria-hidden="true"
         class="absolute inset-0 z-30 cursor-default"
         @contextmenu.prevent></div>

    <div class="mcp-cta-overlay pointer-events-none absolute inset-0 z-40 flex items-end justify-center pb-10 sm:pb-14 opacity-0">
        <div class="rounded-full border border-gray-200/80 bg-white/95 px-4 py-2 text-sm font-medium text-gray-700 shadow-lg backdrop-blur-sm dark:border-white/[0.08] dark:bg-gray-900/95 dark:text-gray-200">
            Try it yourself
            <span class="ml-1 text-primary-600 dark:text-primary-400">→</span>
        </div>
    </div>

    @include('home.partials.hero-agent-shell')

    {{-- Main pane (chat column) --}}
    <div class="flex-1 flex flex-col min-w-0">

        {{-- Conversation title — mirrors app chat-page H1: large, bold, left-aligned, no chrome --}}
        <div class="px-4 sm:px-6 md:px-8 pt-5 pb-3">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white truncate">Overdue tasks this week</h2>
        </div>

        {{-- Messages --}}
        <div x-ref="messagesScroll" class="flex-1 overflow-y-auto p-4 sm:p-6 md:px-8 md:py-6 scroll-smooth">
            <div class="mx-auto w-full max-w-3xl space-y-5 sm:space-y-6">
                @include('home.partials.hero-agent-conversation')
            </div>
        </div>

        @include('home.partials.hero-agent-composer')

    </div>

</div>

<script>
    function heroChat() {
        return {
            // Mirrors theme.css --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1)
            ease: [0.16, 1, 0.3, 1],
            // cycleMs is the total budget for one animation cycle. Exchange 3
            // climaxes near t=10.4s, so 12000ms gives ~1.6s to read the final
            // frame before the hold begins. holdMs is the extra dwell before
            // the next cycle starts.
            prompts: [
                    { text: "What's overdue this week?", charMs: 32 },
                    { text: 'Mark them all as done.', charMs: 22 },
                    { text: "Add Sarah Chen as a contact at @Kovra Systems. She's VP of Engineering.", charMs: 15 }
                ],
            cycleMs: 8800,
            holdMs: 1500,
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            paused: false,
            nextCycleTimer: null,
            pendingTimers: [],

            resetChat() {
                this.$root.querySelectorAll('.mcp-el').forEach(function(el) {
                    el.style.opacity = '0';
                    el.style.transform = '';
                });
                if (this.$refs.messagesScroll) {
                    this.$refs.messagesScroll.scrollTop = 0;
                }
                this.clearComposer();
                var overlay = this.$root.querySelector('.mcp-cta-overlay');
                if (overlay) {
                    overlay.style.opacity = '0';
                    overlay.style.pointerEvents = 'none';
                }
            },

            clearComposer() {
                var typed = this.$root.querySelector('#hero-composer-typed');
                var placeholder = this.$root.querySelector('#hero-composer-placeholder');
                if (typed) typed.textContent = '';
                if (placeholder) placeholder.classList.remove('is-hidden');
            },

            typeIntoComposer(text, charMs) {
                var typed = this.$root.querySelector('#hero-composer-typed');
                var placeholder = this.$root.querySelector('#hero-composer-placeholder');
                if (!typed) return 0;
                if (placeholder) placeholder.classList.add('is-hidden');
                typed.textContent = '';
                var self = this;
                for (var i = 0; i < text.length; i++) {
                    (function(idx) {
                        self.pendingTimers.push(setTimeout(function() {
                            typed.textContent += text.charAt(idx);
                        }, charMs * (idx + 1)));
                    })(i);
                }
                return charMs * text.length;
            },

            flashSend() {
                var btn = this.$root.querySelector('#hero-composer-send');
                if (!btn || typeof animate !== 'function') return;
                animate(btn, { transform: ['scale(1)', 'scale(0.92)', 'scale(1)'] }, { duration: 0.22, ease: this.ease });
            },

            streamText(selector, wordMs) {
                var el = this.$root.querySelector(selector);
                if (!el) return 0;
                var original = el.dataset.streamSource;
                if (!original) {
                    original = el.textContent.trim();
                    el.dataset.streamSource = original;
                }
                var words = original.split(/\s+/);
                el.textContent = '';
                var fragments = [];
                words.forEach(function(word, idx) {
                    var span = document.createElement('span');
                    span.dataset.word = '';
                    span.style.opacity = '0';
                    span.textContent = (idx === 0 ? '' : ' ') + word;
                    el.appendChild(span);
                    fragments.push(span);
                });
                el.style.opacity = '1';
                var self = this;
                fragments.forEach(function(span, idx) {
                    self.pendingTimers.push(setTimeout(function() {
                        if (typeof animate === 'function') {
                            animate(span, { opacity: [0, 1], transform: ['translateY(4px)', 'translateY(0px)'] }, { duration: 0.18, ease: self.ease });
                        } else {
                            span.style.opacity = '1';
                        }
                    }, wordMs * idx));
                });
                return wordMs * words.length;
            },

            cancelInflight() {
                this.$root.querySelectorAll('.mcp-el').forEach(function(el) {
                    if (el.getAnimations) {
                        el.getAnimations().forEach(function(a) { a.cancel(); });
                    }
                });
                if (this.nextCycleTimer) {
                    clearTimeout(this.nextCycleTimer);
                    this.nextCycleTimer = null;
                }
                this.pendingTimers.forEach(function(t) { clearTimeout(t); });
                this.pendingTimers = [];
            },

            showAllImmediate() {
                this.$root.querySelectorAll('.mcp-el').forEach(function(el) {
                    el.style.opacity = '1';
                    el.style.transform = '';
                });
            },

            scrollMessageIntoView(selector) {
                var el = this.$root.querySelector(selector);
                if (!el || !this.$refs.messagesScroll) return;
                var scroller = this.$refs.messagesScroll;
                // offsetTop is relative to the nearest positioned ancestor
                // (the panel root, which has position: relative), not the
                // scroller. Use getBoundingClientRect so the target is the
                // element's screen position relative to the scroller's
                // current scroll viewport, with a 16px headroom above.
                var elTop = el.getBoundingClientRect().top;
                var scrollerTop = scroller.getBoundingClientRect().top;
                var target = scroller.scrollTop + (elTop - scrollerTop) - 16;
                scroller.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
            },

            animateChat() {
                this.cancelInflight();
                this.resetChat();

                if (this.reducedMotion) {
                    this.showAllImmediate();
                    return;
                }

                if (typeof animate !== 'function') {
                    this.showAllImmediate();
                    return;
                }

                this.runCycle();
            },

            runCycle() {
                var root = this.$root;
                var ease = this.ease;
                var self = this;

                // Composer is always visible — bring it up at t=0.
                animate(root.querySelector('.mcp-input'), { opacity: [0, 1] }, { duration: 0.3, ease: ease });

                // ── Empty first frame ── (0.0–0.6s)
                // Nothing fires; the composer reads as a fresh chat ready for input.

                // ── Exchange 1: type → send → stream → tool result ──
                var p1 = this.prompts[0];
                this.pendingTimers.push(setTimeout(function() { self.typeIntoComposer(p1.text, p1.charMs); }, 600));
                var send1At = 600 + p1.charMs * p1.text.length + 50;
                this.pendingTimers.push(setTimeout(function() { self.flashSend(); self.clearComposer(); }, send1At));
                animate(root.querySelector('.mcp-user-1'),   { opacity: [0, 1], transform: ['translateX(12px)', 'translateX(0px)'] }, { delay: send1At / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-avatar-1'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (send1At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-1'),  { opacity: [0, 1] }, { delay: (send1At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-tool-1'),   { opacity: [0, 1], transform: ['translateY(4px)', 'translateY(0px)'] }, { delay: (send1At + 300) / 1000, duration: 0.25, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-1', 65); }, send1At + 600));
                animate(root.querySelector('.mcp-tasks-table'), { opacity: [0, 1] }, { delay: (send1At + 950) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-task-1'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (send1At + 1000) / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-task-2'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (send1At + 1120) / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-task-3'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (send1At + 1240) / 1000, duration: 0.3, ease: ease });

                // ── Exchange 2: bulk approval ──
                var p2 = this.prompts[1];
                var typeStart2 = 3050;
                this.pendingTimers.push(setTimeout(function() { self.scrollMessageIntoView('.mcp-user-2'); }, typeStart2 - 100));
                this.pendingTimers.push(setTimeout(function() { self.typeIntoComposer(p2.text, p2.charMs); }, typeStart2));
                var send2At = typeStart2 + p2.charMs * p2.text.length + 50;
                this.pendingTimers.push(setTimeout(function() { self.flashSend(); self.clearComposer(); }, send2At));
                animate(root.querySelector('.mcp-user-2'),   { opacity: [0, 1], transform: ['translateX(12px)', 'translateX(0px)'] }, { delay: send2At / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-avatar-2'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (send2At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-2'),  { opacity: [0, 1] }, { delay: (send2At + 300) / 1000, duration: 0.25, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-2', 60); }, send2At + 300));
                animate(root.querySelector('.mcp-action-card'), { opacity: [0, 1], transform: ['translateY(8px) scale(0.98)', 'translateY(0px) scale(1)'] }, { delay: (send2At + 750) / 1000, duration: 0.4, ease: ease });

                // ── Exchange 3: create contact (longest prompt) ──
                var p3 = this.prompts[2];
                var typeStart3 = 4850;
                this.pendingTimers.push(setTimeout(function() { self.scrollMessageIntoView('.mcp-user-3'); }, typeStart3 - 100));
                this.pendingTimers.push(setTimeout(function() { self.typeIntoComposer(p3.text, p3.charMs); }, typeStart3));
                var send3At = typeStart3 + p3.charMs * p3.text.length + 50;
                this.pendingTimers.push(setTimeout(function() { self.flashSend(); self.clearComposer(); }, send3At));
                animate(root.querySelector('.mcp-user-3'),   { opacity: [0, 1], transform: ['translateX(12px)', 'translateX(0px)'] }, { delay: send3At / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-avatar-3'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-3'),  { opacity: [0, 1] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-tool-3'),   { opacity: [0, 1], transform: ['translateY(4px)', 'translateY(0px)'] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-3', 60); }, send3At + 600));
                animate(root.querySelector('.mcp-card'),     { opacity: [0, 1], transform: ['scale(0.97)', 'scale(1)'] }, { delay: (send3At + 1050) / 1000, duration: 0.35, ease: ease });

                // ── Closing beat: "Try it yourself" ──
                var overlayInAt = 7200;
                var overlayOutAt = 8500;
                animate(root.querySelector('.mcp-cta-overlay'), { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: overlayInAt / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-cta-overlay'), { opacity: [1, 0] }, { delay: overlayOutAt / 1000, duration: 0.2, ease: ease });

                var totalMs = this.cycleMs + this.holdMs;
                this.nextCycleTimer = setTimeout(function() {
                    if (!self.paused) self.animateChat();
                }, totalMs);
            },

            pause() {
                this.paused = true;
                this.cancelInflight();
            },

            resume() {
                if (!this.paused) return;
                this.paused = false;
                this.animateChat();
            }
        };
    }
</script>
