<div x-data="heroChat()"
     @hero-chat-reset.window="resetChat()"
     @hero-chat-animate.window="animateChat()"
     class="bg-white dark:bg-neutral-950 flex flex-col min-h-[300px] sm:min-h-[400px] md:min-h-[500px]">

    {{-- Messages --}}
    <div class="flex-1 p-4 sm:p-6 md:px-8 md:py-6 space-y-5">

        {{-- User 1 --}}
        <div class="mcp-msg mcp-user flex items-start gap-2.5">
            <div class="w-6 h-6 rounded-full bg-gray-100 dark:bg-white/[0.08] flex items-center justify-center shrink-0 mt-0.5">
                <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400">M</span>
            </div>
            <div class="text-sm text-gray-900 dark:text-gray-100 leading-relaxed pt-0.5">Add Sarah Chen as a contact at Acme Corp. She's VP of Engineering.</div>
        </div>

        {{-- Agent 1 --}}
        <div class="mcp-msg mcp-agent flex items-start gap-2.5">
            <div class="w-6 h-6 rounded-full bg-gradient-to-br from-primary to-primary-400 flex items-center justify-center shrink-0 mt-0.5">
                <x-ri-sparkling-2-fill class="w-3 h-3 text-white"/>
            </div>
            <div class="flex-1 min-w-0">
                <div class="mcp-agent-text text-sm text-gray-600 dark:text-gray-300 leading-relaxed mb-2.5">Done. Created the contact and linked her to Acme Corp.</div>
                <div class="mcp-card bg-gray-50 dark:bg-white/[0.03] rounded-lg p-3 border border-gray-200/80 dark:border-white/[0.06]">
                    <div class="text-sm font-semibold text-gray-900 dark:text-white">Sarah Chen</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">VP of Engineering · Acme Corp</div>
                </div>
            </div>
        </div>

        {{-- User 2 --}}
        <div class="mcp-msg mcp-user flex items-start gap-2.5">
            <div class="w-6 h-6 rounded-full bg-gray-100 dark:bg-white/[0.08] flex items-center justify-center shrink-0 mt-0.5">
                <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400">M</span>
            </div>
            <div class="text-sm text-gray-900 dark:text-gray-100 leading-relaxed pt-0.5">Show me deals closing this quarter over $50K</div>
        </div>

        {{-- Agent 2 --}}
        <div class="mcp-msg mcp-agent flex items-start gap-2.5">
            <div class="w-6 h-6 rounded-full bg-gradient-to-br from-primary to-primary-400 flex items-center justify-center shrink-0 mt-0.5">
                <x-ri-sparkling-2-fill class="w-3 h-3 text-white"/>
            </div>
            <div class="flex-1 min-w-0">
                <div class="mcp-agent-text text-sm text-gray-600 dark:text-gray-300 leading-relaxed mb-2.5">Found <span class="font-medium text-gray-900 dark:text-white">3 deals</span> worth $245K total.</div>
                <div class="space-y-1.5">
                    @foreach([
                        ['Stripe', 'Enterprise Plan', '$120K · closes Apr 2', 'Proposal', 'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10'],
                        ['Notion', 'Team License', '$85K · closes Apr 15', 'Qualified', 'text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-500/10'],
                        ['Linear', 'Add-on Pack', '$40K · closes May 1', 'Lead', 'text-gray-600 dark:text-gray-400 bg-gray-100 dark:bg-white/[0.05]'],
                    ] as [$company, $deal, $meta, $stage, $cls])
                        <div class="mcp-deal flex items-center justify-between bg-gray-50 dark:bg-white/[0.03] rounded-lg px-3 py-2.5 border border-gray-200/80 dark:border-white/[0.06]">
                            <div>
                                <div class="text-[13px] font-medium text-gray-900 dark:text-white">{{ $company }} — {{ $deal }}</div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-px">{{ $meta }}</div>
                            </div>
                            <span class="text-[10px] font-medium px-2 py-0.5 rounded-full {{ $cls }}">{{ $stage }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>

    {{-- Input bar --}}
    <div class="mcp-input border-t border-gray-100 dark:border-white/[0.06] px-4 sm:px-6 md:px-8 py-3">
        <div class="flex items-center gap-3 bg-gray-50 dark:bg-white/[0.03] rounded-lg border border-gray-200/80 dark:border-white/[0.06] px-3.5 py-2.5">
            <x-ri-sparkling-2-fill class="w-4 h-4 text-primary/30 dark:text-primary-400/30 shrink-0"/>
            <span class="text-sm text-gray-400 dark:text-gray-500 flex-1">Ask Relaticle...</span>
            <div class="text-[10px] text-gray-300 dark:text-gray-600 border border-gray-200 dark:border-white/[0.06] rounded px-1.5 py-0.5 font-mono">↵</div>
        </div>
    </div>

</div>

<script>
    function heroChat() {
        return {
            ease: [0.22, 1, 0.36, 1],
            selectors: '.mcp-user, .mcp-agent, .mcp-agent-text, .mcp-card, .mcp-deal, .mcp-input',

            resetChat() {
                this.$root.querySelectorAll(this.selectors).forEach(function(el) {
                    el.style.opacity = '0';
                });
            },

            animateChat() {
                this.resetChat();

                if (typeof animate !== 'function') return;

                var root = this.$root;
                var ease = this.ease;
                var users = root.querySelectorAll('.mcp-user');
                var agents = root.querySelectorAll('.mcp-agent');

                animate(root.querySelector('.mcp-input'), { opacity: [0, 1] }, { duration: 0.3, ease: ease });

                animate(users[0], { opacity: [0, 1], transform: ['translateX(12px)', 'translateX(0px)'] }, { delay: 0.2, duration: 0.4, ease: ease });

                animate(agents[0], { opacity: [0, 1] }, { delay: 0.7, duration: 0.3, ease: ease });
                animate(root.querySelector('.mcp-agent-text'), { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] }, { delay: 0.8, duration: 0.4, ease: ease });
                animate(root.querySelector('.mcp-card'), { opacity: [0, 1], transform: ['translateY(12px)', 'translateY(0px)'] }, { delay: 1.0, duration: 0.5, ease: ease });

                animate(users[1], { opacity: [0, 1], transform: ['translateX(12px)', 'translateX(0px)'] }, { delay: 1.4, duration: 0.4, ease: ease });

                animate(agents[1], { opacity: [0, 1] }, { delay: 1.9, duration: 0.3, ease: ease });
                animate(root.querySelectorAll('.mcp-deal'),
                    { opacity: [0, 1], transform: ['translateX(-8px)', 'translateX(0px)'] },
                    { delay: stagger(0.1, { start: 2.1 }), duration: 0.35, ease: ease }
                );
            }
        };
    }
</script>
