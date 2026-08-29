// Model picker helpers shared by every composer surface (full-page chat,
// side panel, dashboard). Each caller supplies its own `providerIcons` map
// (the SVG markup is server-rendered via Blade's svg() helper, so it can't
// live in a static JS module): everything else here is pure logic that used
// to be copy-pasted per Alpine root, including the hardcoded Anthropic brand
// color. Spread into an Alpine.data() factory alongside `modelOptions`:
//
//   ...window.ChatModules.modelPickerModule({ providerIcons: @js([...]) }),
//   modelOptions: @js(...),
export function modelPickerModule({ providerIcons, persistSelection = false }) {
    return {
        providerIcons,
        selectedModel: 'auto',
        // Set when a plan-locked option is clicked; the picker renders an
        // inline upgrade hint (a silent no-op taught users the row was broken).
        lockedModelHint: false,

        selectModel(value) {
            if (!this.allowedModels.includes(value)) {
                this.lockedModelHint = true;
                return;
            }
            this.lockedModelHint = false;
            this.selectedModel = value;
            if (persistSelection) {
                try { localStorage.setItem('chat:model', value); } catch (_) { /* ignore */ }
            }
        },

        providerIconHtml(provider) {
            if (!provider) return '';
            return this.providerIcons[provider] || '';
        },

        providerIconColor(provider) {
            return ({
                anthropic: 'text-anthropic',
                openai: 'text-gray-900 dark:text-gray-200',
                ollama: 'text-gray-500 dark:text-gray-400',
                selfhosted: 'text-gray-500 dark:text-gray-400',
            })[provider] || '';
        },

        modelLabel(value) {
            const found = this.modelOptions.find((o) => o.value === value);
            return (found ?? this.modelOptions[0])?.label ?? '';
        },

        modelProvider(value) {
            const found = this.modelOptions.find((o) => o.value === value);
            return found?.provider ?? null;
        },
    };
}
