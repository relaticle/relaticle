<div>
    {{-- Side Panel --}}
    <div
        x-data="{
            open: @entangle('isOpen'),
            width: Math.max(360, Math.min(720, parseInt(localStorage.getItem('chat-panel-width') || '420', 10) || 420)),
            minWidth: 360,
            maxWidth: 720,
            resizing: false,
            viewportWidth: window.innerWidth,

            init() {
                this.$watch('open', (newValue) => {
                    if (newValue === true) {
                        this.$nextTick(() => {
                            window.dispatchEvent(new CustomEvent('chat:focus-editor', { detail: { context: 'side-panel' } }));
                        });
                    }
                });

                this.keydownHandler = (e) => {
                    if ((e.metaKey || e.ctrlKey) && e.key === 'j') {
                        e.preventDefault();
                        this.open = !this.open;
                        return;
                    }
                    if (e.key === 'Escape' && this.open) {
                        e.preventDefault();
                        this.open = false;
                    }
                };
                window.addEventListener('keydown', this.keydownHandler);

                this.chatSendHandler = (e) => {
                    if (e.detail?.message) {
                        this.open = true;
                        $wire.handleSendFromDashboard(e.detail.message, e.detail.source ?? 'dashboard');
                    }
                };
                window.addEventListener('chat:send', this.chatSendHandler);

                this.navigatedHandler = () => {
                    $wire.refreshContext();
                };
                document.addEventListener('livewire:navigated', this.navigatedHandler);

                this.resizeHandler = () => { this.viewportWidth = window.innerWidth; };
                window.addEventListener('resize', this.resizeHandler);
            },

            destroy() {
                window.removeEventListener('keydown', this.keydownHandler);
                window.removeEventListener('chat:send', this.chatSendHandler);
                document.removeEventListener('livewire:navigated', this.navigatedHandler);
                window.removeEventListener('resize', this.resizeHandler);
            },

            startResize(e) {
                this.resizing = true;
                const startX = e.clientX;
                const startWidth = this.width;

                const onMouseMove = (moveEvent) => {
                    const delta = startX - moveEvent.clientX;
                    this.width = Math.max(this.minWidth, Math.min(this.maxWidth, startWidth + delta));
                };

                const onMouseUp = () => {
                    this.resizing = false;
                    localStorage.setItem('chat-panel-width', this.width.toString());
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                };

                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
            }
        }"
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        x-cloak
        role="dialog"
        aria-modal="true"
        aria-label="Chat side panel"
        tabindex="-1"
        class="fixed inset-y-0 right-0 z-50 flex max-w-full"
        :style="{ width: (viewportWidth < 640 ? viewportWidth : width) + 'px' }"
        data-chat-side-panel
    >
        {{-- Resize Handle --}}
        <div
            @mousedown="startResize($event)"
            class="w-1 cursor-col-resize bg-gray-200 transition-colors hover:bg-primary-400 dark:bg-gray-700 dark:hover:bg-primary-600"
            :class="{ 'bg-primary-500 dark:bg-primary-500': resizing }"
        ></div>

        {{-- Panel Content --}}
        <div class="flex flex-1 flex-col overflow-hidden border-l border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
            {{-- Panel Header --}}
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chat-bubble-left-right class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Chat</h3>
                </div>
                <button
                    @click="open = false"
                    type="button"
                    aria-label="Close chat panel"
                    class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {{-- Chat Content Area --}}
            <div class="flex-1 overflow-y-auto" data-chat-messages>
                @livewire('chat.chat-interface', [
                    'conversationId' => $conversationId,
                    'context' => 'side-panel',
                ], key('side-panel-chat-' . ($conversationId ?? 'new')))
            </div>

            {{-- Context-Aware Suggested Prompts --}}
            @if(!empty($suggestedPrompts))
                <div class="border-t border-gray-200 px-4 py-2 dark:border-gray-700">
                    <x-chat.suggested-prompts :prompts="$suggestedPrompts" />
                </div>
            @endif

            {{-- Credit balance footer --}}
            @php
                $allowance = $this->plan->credits();
                $exceedsAllowance = $this->creditsRemaining > $allowance;
                $usedPct = $allowance > 0 && ! $exceedsAllowance
                    ? 1 - ($this->creditsRemaining / $allowance)
                    : 0;
            @endphp
            <div class="border-t border-gray-200 px-4 py-2.5 dark:border-gray-700">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-medium text-gray-700 dark:text-gray-300">
                        {{ $this->plan->label() }}
                    </span>
                    <span @class([
                        'tabular-nums',
                        'text-gray-500 dark:text-gray-400' => $usedPct < 0.5,
                        'text-amber-600 dark:text-amber-400' => $usedPct >= 0.5 && $usedPct < 0.8,
                        'text-red-600 dark:text-red-400' => $usedPct >= 0.8,
                    ])>
                        {{ number_format($this->creditsRemaining) }}@unless ($exceedsAllowance) / {{ number_format($allowance) }}@endunless
                    </span>
                </div>
                @php($billingTeam = \Filament\Facades\Filament::getTenant())
                @if ($billingTeam && $this->plan === \App\Enums\Plan::Free && \Laravel\Pennant\Feature::active(\App\Features\Billing::class))
                    <a
                        href="{{ url("/app/{$billingTeam->slug}/billing") }}"
                        class="mt-1 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400"
                    >
                        Upgrade to Pro →
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Toggle button is now rendered in the topbar via GLOBAL_SEARCH_BEFORE render hook (chat-topbar-toggle-hook). Cmd+J keyboard shortcut still works via the keydown handler above. --}}
</div>
