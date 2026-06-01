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
        /* Soft-mask the scroll edges so messages that scroll past the top/bottom
           fade out instead of getting hard-clipped mid-row. */
        -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 28px, #000 calc(100% - 20px), transparent 100%);
                mask-image: linear-gradient(to bottom, transparent 0, #000 28px, #000 calc(100% - 20px), transparent 100%);
    }
    .hero-agent-preview .overflow-y-auto::-webkit-scrollbar {
        display: none;
    }
    /* Conversation title starts hidden so the entry overlay reads as the
       only foreground surface; the transition fades it in. The sidebar is
       always visible — it mirrors the real dashboard's persistent shell. */
    .hero-agent-preview .hero-agent-title { opacity: 0; }
</style>

<div x-data="heroChat()"
     @hero-chat-reset.window="cancelInflight(); resetChat()"
     @hero-chat-animate.window="animateChat()"
     class="hero-agent-preview relative bg-gray-50 dark:bg-gray-950 flex h-[520px] sm:h-[580px] md:h-[640px]">

    {{-- Non-interactive overlay: blocks clicks, right-click, and drag.
         z-30 puts it above all panel content. --}}
    <div aria-hidden="true"
         class="absolute inset-0 z-30 cursor-default"
         @contextmenu.prevent></div>

    @include('home.partials.hero-agent-shell')

    {{-- Main pane (chat column). Relative so the entry overlay can absolutely
         position itself within this column instead of the whole panel — that
         keeps the sidebar visible during the entry phase too. --}}
    <div class="relative flex-1 flex flex-col min-w-0">

        {{-- Conversation title — mirrors app chat-page H1: large, bold, left-aligned, no chrome --}}
        <div class="hero-agent-title px-4 sm:px-6 md:px-8 pt-5 pb-3">
            <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white truncate">Overdue tasks this week</h2>
        </div>

        {{-- Messages --}}
        <div x-ref="messagesScroll" class="flex-1 overflow-y-auto p-4 sm:p-6 md:px-8 md:py-6 scroll-smooth">
            <div class="mx-auto w-full max-w-3xl space-y-5 sm:space-y-6">
                @include('home.partials.hero-agent-conversation')
            </div>
        </div>

        @include('home.partials.hero-agent-composer')

        {{-- Entry overlay — covers the chat column only, leaving the sidebar
             visible. Driven by heroChat phase state. --}}
        @include('home.partials.hero-agent-entry')

    </div>

</div>

<script>
    function heroChat() {
        return {
            // Mirrors theme.css --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1)
            ease: [0.16, 1, 0.3, 1],
            // Per-prompt charMs / per-stream wordMs are tuned for an unhurried,
            // readable pace. holdMs lets the viewer read the final record card
            // before the loop restarts.
            prompts: [
                { text: "What's overdue this week?", charMs: 55 },
                { text: 'Mark them all as done.', charMs: 38 },
                { text: "Add Sarah Chen as a contact at @Kovra Systems. She's VP of Engineering.", charMs: 28 }
            ],
            // Entry phase budget — first prompt is typed into the centered
            // dashboard composer (mirrors app /), then the screen transitions
            // into the conversation view where exchanges 2 and 3 continue.
            entryHoldMs: 600,
            entryTransitionMs: 700,
            holdMs: 1500,
            reducedMotion: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            nextCycleTimer: null,
            pendingTimers: [],

            resetChat() {
                this.$root.querySelectorAll('.mcp-el').forEach(function(el) {
                    el.style.opacity = '0';
                    el.style.transform = '';
                });
                // Conversation title has its own CSS opacity:0 rule so it
                // doesn't ghost in before the transition. Clear inline styles
                // so the CSS default re-applies. Sidebar is always visible —
                // mirrors the real dashboard — so it's not touched here.
                this.$root.querySelectorAll('.hero-agent-title').forEach(function(el) {
                    el.style.opacity = '';
                    el.style.transform = '';
                });
                this.$root.querySelectorAll('.mcp-approve-actions').forEach(function(el) {
                    el.style.opacity = '';
                    el.style.transform = '';
                });
                if (this.$refs.messagesScroll) {
                    this.$refs.messagesScroll.scrollTop = 0;
                }
                this.clearComposer();
                this.clearEntryComposer();
            },

            // Reset only the entry (dashboard) layer so it can replay its intro,
            // without disturbing a conversation that may still be on screen.
            resetEntryOnly() {
                this.$root.querySelectorAll('.mcp-entry-greeting, .mcp-entry-recent, .mcp-entry-composer, .mcp-entry-chips, .mcp-entry-tasks').forEach(function(el) {
                    el.style.opacity = '0';
                    el.style.transform = '';
                });
                this.clearEntryComposer();
            },

            // Reset only the conversation layer (messages, cards, title, scroll,
            // composer). Run while the entry curtain covers the column so the
            // clear is invisible. Leaves the entry layer untouched.
            resetConversationOnly() {
                this.$root.querySelectorAll('.mcp-el').forEach(function(el) {
                    if (el.classList.contains('hero-agent-entry')) return;
                    if (el.className.indexOf('mcp-entry') !== -1) return;
                    el.style.opacity = '0';
                    el.style.transform = '';
                });
                this.$root.querySelectorAll('.hero-agent-title').forEach(function(el) {
                    el.style.opacity = '';
                    el.style.transform = '';
                });
                this.$root.querySelectorAll('.mcp-approve-actions').forEach(function(el) {
                    el.style.opacity = '';
                    el.style.transform = '';
                });
                if (this.$refs.messagesScroll) {
                    this.$refs.messagesScroll.scrollTop = 0;
                }
                this.clearComposer();
            },

            clearComposer() {
                this.clearTypedNode('#hero-composer-typed', '#hero-composer-placeholder');
            },

            clearEntryComposer() {
                this.clearTypedNode('#hero-entry-typed', '#hero-entry-placeholder');
            },

            clearTypedNode(typedSelector, placeholderSelector) {
                var typed = this.$root.querySelector(typedSelector);
                var placeholder = this.$root.querySelector(placeholderSelector);
                if (typed) typed.textContent = '';
                if (placeholder) placeholder.classList.remove('is-hidden');
            },

            typeIntoComposer(text, charMs) {
                return this.typeIntoNode('#hero-composer-typed', '#hero-composer-placeholder', text, charMs);
            },

            typeIntoEntryComposer(text, charMs) {
                return this.typeIntoNode('#hero-entry-typed', '#hero-entry-placeholder', text, charMs);
            },

            typeIntoNode(typedSelector, placeholderSelector, text, charMs) {
                var typed = this.$root.querySelector(typedSelector);
                var placeholder = this.$root.querySelector(placeholderSelector);
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
                this.flashButton('#hero-composer-send');
            },

            flashEntrySend() {
                this.flashButton('#hero-entry-send');
            },

            flashButton(selector) {
                var btn = this.$root.querySelector(selector);
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
                // .mcp-approve-actions isn't an .mcp-el but is animated (the
                // approve cross-fade), so include it — otherwise its in-flight /
                // delayed animation keeps holding opacity and overrides a static
                // reset (e.g. the reduced-motion view after a tab switch).
                this.$root.querySelectorAll('.mcp-el, .mcp-approve-actions').forEach(function(el) {
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
                // Reduced-motion fallback: viewer sees the final conversation
                // state, not the entry overlay. Title must override its CSS
                // opacity:0 default.
                this.$root.querySelectorAll('.hero-agent-title').forEach(function(el) {
                    el.style.opacity = '1';
                });
                // Static view shows the resolved approval (the confirmed overlay
                // is an .mcp-el shown above); hide the pending buttons so they
                // don't stack underneath it.
                this.$root.querySelectorAll('.mcp-approve-actions').forEach(function(el) {
                    el.style.opacity = '0';
                });
                var entry = this.$root.querySelector('.hero-agent-entry');
                if (entry) entry.style.opacity = '0';
                this.clearComposer();
                this.clearEntryComposer();
            },

            transitionToConversation() {
                var ease = this.ease;
                var d = this.entryTransitionMs / 1000;

                var entry = this.$root.querySelector('.hero-agent-entry');
                if (entry && typeof animate === 'function') {
                    animate(entry, {
                        opacity: [1, 0],
                        transform: ['scale(1)', 'scale(0.985)']
                    }, { duration: d, ease: ease });
                }

                var title = this.$root.querySelector('.hero-agent-title');
                if (title && typeof animate === 'function') {
                    animate(title, {
                        opacity: [0, 1],
                        transform: ['translateY(-4px)', 'translateY(0px)']
                    }, { duration: d * 0.85, delay: d * 0.15, ease: ease });
                }
            },

            // Keep the conversation anchored to the bottom like a real chat:
            // reveal the latest element just above the composer and only ever
            // scroll DOWN, so earlier messages stay on screen and slide up
            // gradually instead of being yanked to the top out of view.
            scrollToShow(selector, pad) {
                var el = this.$root.querySelector(selector);
                if (!el || !this.$refs.messagesScroll) return;
                var scroller = this.$refs.messagesScroll;
                if (pad == null) pad = 28;
                var elRect = el.getBoundingClientRect();
                var scRect = scroller.getBoundingClientRect();
                var elBottom = scroller.scrollTop + (elRect.bottom - scRect.top);
                var target = elBottom - scroller.clientHeight + pad;
                if (target > scroller.scrollTop + 1) {
                    scroller.scrollTo({ top: target, behavior: 'smooth' });
                }
            },

            animateChat() {
                this.cancelInflight();

                if (this.reducedMotion || typeof animate !== 'function') {
                    this.resetChat();
                    this.showAllImmediate();
                    return;
                }

                // Prep the entry view for a fresh intro but leave any existing
                // conversation on screen. runCycle() raises the entry "curtain"
                // over the previous conversation (a cross-fade) and clears it
                // underneath once covered — so the loop never hard-cuts to a
                // blank panel. On first load there's nothing behind the curtain,
                // so it simply fades in from the empty shell.
                this.resetEntryOnly();
                this.runCycle();
            },

            runCycle() {
                var root = this.$root;
                var ease = this.ease;
                var self = this;

                // ── Entry phase ──
                // The viewer lands on the centered dashboard (greeting +
                // composer + chips), watches prompt 1 get typed, then sees
                // the screen transition into the conversation view.
                var entry = root.querySelector('.hero-agent-entry');
                if (entry) {
                    // Reset the slight scale-down left by the previous cycle's
                    // exit transition and fade the curtain in over the old
                    // conversation (or the empty shell on first load).
                    animate(entry, { opacity: [0, 1], transform: ['scale(0.99)', 'scale(1)'] }, { duration: 0.4, ease: ease });
                }
                animate(root.querySelector('.mcp-entry-greeting'),  { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { duration: 0.4, delay: 0.05, ease: ease });
                animate(root.querySelector('.mcp-entry-recent'),    { opacity: [0, 1] }, { duration: 0.4, delay: 0.18, ease: ease });
                animate(root.querySelector('.mcp-entry-composer'),  { opacity: [0, 1], transform: ['translateY(12px)', 'translateY(0px)'] }, { duration: 0.4, delay: 0.22, ease: ease });
                animate(root.querySelector('.mcp-entry-chips'),     { opacity: [0, 1], transform: ['translateY(6px)', 'translateY(0px)'] }, { duration: 0.4, delay: 0.32, ease: ease });
                animate(root.querySelector('.mcp-entry-tasks'),     { opacity: [0, 1], transform: ['translateY(6px)', 'translateY(0px)'] }, { duration: 0.4, delay: 0.42, ease: ease });

                // Loop restart: the entry curtain (above) has now faded in over
                // the previous conversation. Clear that conversation underneath
                // once the curtain is opaque so the next cycle starts clean — no
                // blank-panel flash. No-op on first load.
                this.pendingTimers.push(setTimeout(function() { self.resetConversationOnly(); }, 420));

                // Type prompt 1 into the entry composer, then send.
                var p1 = this.prompts[0];
                var entryTypeAt = this.entryHoldMs;
                var entrySendAt = entryTypeAt + p1.charMs * p1.text.length + 50;
                var transitionAt = entrySendAt + 200;
                this.pendingTimers.push(setTimeout(function() { self.typeIntoEntryComposer(p1.text, p1.charMs); }, entryTypeAt));
                this.pendingTimers.push(setTimeout(function() { self.flashEntrySend(); self.clearEntryComposer(); }, entrySendAt));

                // Transition into the conversation view.
                this.pendingTimers.push(setTimeout(function() { self.transitionToConversation(); }, transitionAt));

                // Conversation composer fades in during the transition so it's
                // ready for prompts 2 and 3.
                animate(root.querySelector('.mcp-input'), { opacity: [0, 1] }, { duration: 0.4, delay: transitionAt / 1000, ease: ease });

                var conversationStart = transitionAt + this.entryTransitionMs;

                // ── Exchange 1: user bubble lands as the transition completes ──
                animate(root.querySelector('.mcp-user-1'),   { opacity: [0, 1], transform: ['translateY(10px)', 'translateY(0px)'] }, { delay: conversationStart / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-avatar-1'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (conversationStart + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-1'),  { opacity: [0, 1] }, { delay: (conversationStart + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-tool-1'),   { opacity: [0, 1], transform: ['translateY(4px)', 'translateY(0px)'] }, { delay: (conversationStart + 300) / 1000, duration: 0.25, ease: ease });
                // The tool "runs" for a beat, then its done badge lands and the
                // answer streams — so the call reads as work, not an instant result.
                animate(root.querySelector('.mcp-tool-1 .mcp-tool-done'), { opacity: [0, 1] }, { delay: (conversationStart + 600) / 1000, duration: 0.2, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-1', 95); }, conversationStart + 700));
                animate(root.querySelector('.mcp-tasks-table'), { opacity: [0, 1] }, { delay: (conversationStart + 950) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-task-1'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (conversationStart + 1000) / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-task-2'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (conversationStart + 1120) / 1000, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-task-3'),   { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: (conversationStart + 1240) / 1000, duration: 0.3, ease: ease });
                // Follow the result table down into view if it runs past the fold
                // (e.g. the shorter mobile panel); a no-op when it already fits.
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-tasks-table'); }, conversationStart + 1300));

                // ── Exchange 2: bulk approval ──
                // The composer types while the view stays on exchange 1; the new
                // message and its response then reveal at the bottom and the view
                // follows each one down, leaving exchange 1 visible above.
                var p2 = this.prompts[1];
                var typeStart2 = conversationStart + 2700;
                this.pendingTimers.push(setTimeout(function() { self.typeIntoComposer(p2.text, p2.charMs); }, typeStart2));
                var send2At = typeStart2 + p2.charMs * p2.text.length + 50;
                this.pendingTimers.push(setTimeout(function() { self.flashSend(); self.clearComposer(); }, send2At));
                animate(root.querySelector('.mcp-user-2'),   { opacity: [0, 1], transform: ['translateY(10px)', 'translateY(0px)'] }, { delay: send2At / 1000, duration: 0.3, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-user-2'); }, send2At));
                animate(root.querySelector('.mcp-avatar-2'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (send2At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-2'),  { opacity: [0, 1] }, { delay: (send2At + 300) / 1000, duration: 0.25, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-avatar-2'); }, send2At + 320));
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-2', 90); }, send2At + 300));
                animate(root.querySelector('.mcp-action-card'), { opacity: [0, 1], transform: ['translateY(8px) scale(0.98)', 'translateY(0px) scale(1)'] }, { delay: (send2At + 750) / 1000, duration: 0.4, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-action-card'); }, send2At + 780));

                // Resolve the approval: press Approve, then cross-fade the
                // buttons to a confirmed state. Completes the safe-approval
                // story before the next prompt rather than leaving it pending.
                var approveAt = send2At + 1300;
                this.pendingTimers.push(setTimeout(function() { self.flashButton('#hero-approve-btn'); }, approveAt));
                animate(root.querySelector('.mcp-approve-actions'), { opacity: [1, 0] }, { delay: (approveAt + 200) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-approve-done'),    { opacity: [0, 1], transform: ['translateY(3px)', 'translateY(0px)'] }, { delay: (approveAt + 340) / 1000, duration: 0.3, ease: ease });

                // ── Exchange 3: create contact (longest prompt) ──
                var p3 = this.prompts[2];
                var typeStart3 = send2At + 2500;
                this.pendingTimers.push(setTimeout(function() { self.typeIntoComposer(p3.text, p3.charMs); }, typeStart3));
                var send3At = typeStart3 + p3.charMs * p3.text.length + 50;
                this.pendingTimers.push(setTimeout(function() { self.flashSend(); self.clearComposer(); }, send3At));
                animate(root.querySelector('.mcp-user-3'),   { opacity: [0, 1], transform: ['translateY(10px)', 'translateY(0px)'] }, { delay: send3At / 1000, duration: 0.3, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-user-3'); }, send3At));
                animate(root.querySelector('.mcp-avatar-3'), { opacity: [0, 1], transform: ['scale(0.95)', 'scale(1)'] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-label-3'),  { opacity: [0, 1] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-tool-3'),   { opacity: [0, 1], transform: ['translateY(4px)', 'translateY(0px)'] }, { delay: (send3At + 300) / 1000, duration: 0.25, ease: ease });
                animate(root.querySelector('.mcp-tool-3 .mcp-tool-done'), { opacity: [0, 1] }, { delay: (send3At + 600) / 1000, duration: 0.2, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-avatar-3'); }, send3At + 320));
                this.pendingTimers.push(setTimeout(function() { self.streamText('.mcp-text-3', 90); }, send3At + 700));
                animate(root.querySelector('.mcp-card'),     { opacity: [0, 1], transform: ['scale(0.97)', 'scale(1)'] }, { delay: (send3At + 1050) / 1000, duration: 0.35, ease: ease });
                this.pendingTimers.push(setTimeout(function() { self.scrollToShow('.mcp-card'); }, send3At + 1080));

                var cycleEnd = send3At + 1400;
                var totalMs = cycleEnd + this.holdMs;
                this.nextCycleTimer = setTimeout(function() {
                    self.animateChat();
                }, totalMs);
            }
        };
    }
</script>
