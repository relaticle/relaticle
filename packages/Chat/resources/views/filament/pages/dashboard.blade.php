<x-filament-panels::page>
    <div
        x-data="dashboardChatInput(@js(\App\Filament\Pages\ChatConversation::getUrl()), @js(auth()->user()?->ai_preferences['default_model'] ?? 'auto'))"
        class="mx-auto w-full max-w-3xl py-16"
    >
        {{-- Greeting --}}
        <div class="text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                {{ $this->getGreeting() }}
            </h1>

            @if($recentChatId)
                <a
                    href="{{ \App\Filament\Pages\ChatConversation::getUrl(['conversationId' => $recentChatId]) }}"
                    class="mt-2 inline-flex items-center gap-1.5 text-sm text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    <span>Recent chat &middot; {{ \Illuminate\Support\Str::limit($recentChatTitle ?? 'Untitled', 50) }}</span>
                </a>
            @endif
        </div>

        {{-- Chat input --}}
        <form @submit.prevent="submit()" class="mt-10">
            <div
                x-data="chatEditor({
                    initialDocument: { type: 'doc', content: [] },
                    placeholder: 'Ask anything...',
                    autofocus: true,
                    onSubmit: () => $root.dispatchEvent(new CustomEvent('dashboard:editor-submit', { bubbles: true })),
                    onChange: ({ document, text }) => {
                        $root.dispatchEvent(new CustomEvent('dashboard:editor-change', { bubbles: true, detail: { document, text } }));
                    },
                })"
                x-on:dashboard:editor-submit.window="submit()"
                x-on:dashboard:editor-change.window="input = $event.detail.text"
                data-chat-context="dashboard"
                class="relative rounded-2xl border border-gray-200 bg-white transition focus-within:border-primary-500 dark:border-gray-700 dark:bg-gray-800"
            >
                <div x-ref="editor" wire:ignore class="relative"></div>

                <div class="flex items-center justify-between gap-2 px-3 pb-2">
                    <span
                        x-show="text.length > 4000"
                        x-cloak
                        x-text="`${text.length.toLocaleString()} / 5,000`"
                        :class="{
                            'text-gray-500 dark:text-gray-400': text.length <= 4900,
                            'text-amber-600 dark:text-amber-400': text.length > 4900 && text.length <= 5000,
                            'text-red-600 dark:text-red-400': text.length > 5000,
                        }"
                        class="text-[11px]"
                        aria-live="polite"
                    ></span>
                    <div x-show="text.length <= 4000" class="flex-1"></div>

                    <div class="flex items-center gap-2">
                        @include('chat::livewire.chat.partials._model-picker')

                        <button
                            type="submit"
                            class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-white transition hover:bg-primary-700 disabled:bg-primary-200 disabled:text-white dark:disabled:bg-primary-900/40 dark:disabled:text-primary-300"
                            :disabled="text.trim().length === 0 || text.length > 5000 || submitting"
                            aria-label="Send message"
                        >
                            <x-heroicon-s-arrow-up class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div
                x-show="error"
                x-cloak
                role="alert"
                class="mt-2 text-xs text-red-600 dark:text-red-400"
                x-text="error"
            ></div>
        </form>

        @include('chat::filament.pages.partials.my-tasks')
    </div>

    @script
    <script>
        Alpine.data('dashboardChatInput', (chatUrl, defaultModel) => ({
            input: '',
            submitting: false,
            error: null,
            currentPlan: @js(auth()->user()?->currentTeam?->plan?->value ?? \App\Enums\Plan::default()->value),
            currentPlanLabel: @js(auth()->user()?->currentTeam?->plan?->label() ?? \App\Enums\Plan::default()->label()),
            allowedModels: @js(
                collect((auth()->user()?->currentTeam?->plan ?? \App\Enums\Plan::default())->allowedModels())
                    ->map(fn ($m) => $m->value)
                    ->all()
            ),
            selectedModel: 'auto',
            modelOptions: [
                { value: 'auto', label: 'Auto', provider: null },
                { value: 'claude-sonnet', label: 'Sonnet 4.6', provider: 'anthropic' },
                { value: 'claude-opus', label: 'Opus 4.7', provider: 'anthropic' },
                { value: 'gpt-5-5', label: 'GPT 5.5', provider: 'openai' },
                { value: 'gpt-5-4', label: 'GPT 5.4', provider: 'openai' },
            ],
            providerIcons: @js([
                'anthropic' => svg('ri-claude-fill')->toHtml(),
                'openai' => svg('ri-openai-fill')->toHtml(),
            ]),

            providerIconHtml(provider) {
                return provider ? (this.providerIcons[provider] || '') : '';
            },

            providerIconColor(provider) {
                return ({
                    anthropic: 'text-[#D4763C]',
                    openai: 'text-gray-900 dark:text-gray-200',
                })[provider] || '';
            },

            modelLabel(value) {
                const found = this.modelOptions.find((o) => o.value === value);
                return (found || this.modelOptions[0]).label;
            },

            modelProvider(value) {
                const found = this.modelOptions.find((o) => o.value === value);
                return found?.provider ?? null;
            },

            init() {
                const candidate = defaultModel || 'auto';
                this.selectedModel = this.allowedModels.includes(candidate) ? candidate : 'auto';
            },

            selectModel(value) {
                if (! this.allowedModels.includes(value)) {
                    window.dispatchEvent(new CustomEvent('chat:model-locked', {
                        detail: { model: value, plan: this.currentPlan, planLabel: this.currentPlanLabel },
                    }));
                    return;
                }
                this.selectedModel = value;
            },

            // Scoped lookup of the dashboard's TipTap editor — avoids the
            // window.__dashboardEditor global which collides if any sibling
            // chat-interface instance also writes its own global. We use
            // document.querySelector keyed by data-chat-context to dodge the
            // same Livewire-morph stale-root problem documented on the
            // chatInterface.localEditor() helper.
            localEditor() {
                const wrapper = document.querySelector('[data-chat-context="dashboard"][x-data*="chatEditor"]');
                if (! wrapper || ! window.Alpine) return null;
                return window.Alpine.$data(wrapper);
            },

            submit() {
                const editor = this.localEditor();
                if (!editor || editor.getText().trim().length === 0 || this.submitting) return;

                this.submitting = true;
                this.error = null;

                // Hand the editor document to the conversation page via sessionStorage
                // and navigate immediately. The conversation page picks up the bootstrap
                // payload in chatInterface.init(), restores the editor (preserving
                // mentions), and fires the first-message POST from there. This avoids
                // a long wait on the dashboard when the queue is slow or running sync.
                try {
                    sessionStorage.setItem('chat:bootstrap', JSON.stringify({
                        document: editor.getDocument(),
                        model: this.selectedModel,
                    }));
                } catch (_) {
                    this.error = 'Could not save message. Try again.';
                    this.submitting = false;
                    return;
                }

                window.location.href = chatUrl;
            },
        }));
    </script>
    @endscript
</x-filament-panels::page>
