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
                    placeholder: @js(__('Ask anything...')),
                    autofocus: true,
                    onSubmit: () => $root.dispatchEvent(new CustomEvent('dashboard:editor-submit', { bubbles: true })),
                    onChange: ({ document, text }) => {
                        $root.dispatchEvent(new CustomEvent('dashboard:editor-change', { bubbles: true, detail: { document, text } }));
                    },
                })"
                x-on:dashboard:editor-submit.window="submit()"
                x-on:dashboard:editor-change.window="input = $event.detail.text"
                data-chat-context="dashboard"
            >
                @include('chat::livewire.chat.partials._composer-bar', [
                    'showStopButton' => false,
                    'sendDisabled' => 'text.trim().length === 0 || text.length > 5000 || submitting',
                ])
            </div>

            <div
                x-show="error"
                x-cloak
                role="alert"
                class="mt-2 text-xs text-red-600 dark:text-red-400"
                x-text="error"
            ></div>

            {{-- Prompt suggestions: the composer's counterpart to the empty-state
                 chips the full-page chat shows, so Home is the only place a chat
                 has to be started from. --}}
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                <template x-for="starter in starterPrompts" :key="starter.label">
                    <button
                        type="button"
                        x-on:click="useStarter(starter.prompt)"
                        x-text="starter.label"
                        class="inline-flex items-center gap-1.5 rounded-full border border-[var(--surface-card-border)] bg-[var(--surface-card-bg)] px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-300 dark:hover:border-primary-700 dark:hover:bg-primary-900/20 dark:hover:text-primary-300"
                    ></button>
                </template>
            </div>
        </form>

        @include('chat::filament.pages.partials.my-tasks')
    </div>

    @script
    <script>
        Alpine.data('dashboardChatInput', (chatUrl, defaultModel) => ({
            input: '',
            submitting: false,
            error: null,
            starterPrompts: @js($this->starterPrompts),
            currentPlan: @js(auth()->user()?->currentTeam?->plan?->value ?? \App\Enums\Plan::default()->value),
            currentPlanLabel: @js(auth()->user()?->currentTeam?->plan?->label() ?? \App\Enums\Plan::default()->label()),
            allowedModels: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->allowedIdsFor(auth()->user()?->currentTeam?->plan ?? \App\Enums\Plan::default())),
            selectedModel: 'auto',
            modelOptions: @js(app(\Relaticle\Chat\Services\ModelRegistry::class)->pickerOptions()),
            ...window.ChatModules.modelPickerModule({
                providerIcons: @js([
                    'anthropic' => svg('ri-claude-fill')->toHtml(),
                    'openai' => svg('ri-openai-fill')->toHtml(),
                    'ollama' => svg('ri-server-line')->toHtml(),
                    'selfhosted' => svg('ri-server-line')->toHtml(),
                ]),
            }),

            init() {
                const candidate = defaultModel || 'auto';
                this.selectedModel = this.allowedModels.includes(candidate)
                    && this.modelOptions.some((o) => o.value === candidate)
                    ? candidate
                    : 'auto';
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

            useStarter(prompt) {
                const editor = this.localEditor();
                if (!editor || this.submitting) return;

                editor.setText(prompt);
                this.input = prompt;
                this.$nextTick(() => this.submit());
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
