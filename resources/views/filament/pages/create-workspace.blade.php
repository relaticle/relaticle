<x-onboarding.shell>
    <div
        class="flex h-full"
        x-data="{
            wizardStep: 0,
            focused: null,
            hovered: null,
            blurTimer: null,
            regionFor(element) {
                if (element.closest('[data-logo-upload]')) {
                    return 'workspace'
                }

                const model = [...element.attributes].find((attribute) => attribute.name.startsWith('wire:model'))?.value

                return { 'data.name': 'workspace', 'data.slug': 'workspace', 'data.user_name': 'user' }[model] ?? null
            },
        }"
        x-on:onboarding-step-changed.window="wizardStep = $event.detail.index"
        x-on:focusin="clearTimeout(blurTimer); focused = regionFor($event.target)"
        x-on:focusout="blurTimer = setTimeout(() => focused = null, 80)"
        x-on:mouseover="hovered = $event.target.closest('[data-logo-upload]') ? 'workspace' : null"
        x-on:mouseleave="hovered = null"
    >
        {{-- Left: form, with a way back out for anyone who already has a workspace --}}
        <div class="flex flex-1 flex-col px-10 py-10 sm:px-12 sm:py-12">
            {{ $this->content }}

            @php($cancelUrl = $this->getCancelUrl())

            {{-- Leaving the wizard belongs to the first step. From step two onward
                 "Back" is the way out, and a third stacked link only competes with it. --}}
            @if (filled($cancelUrl))
                {{-- This link mounts inside the wizard, after the first step change has
                     already been announced, so it asks for the current step instead of
                     assuming step zero. --}}
                <div
                    x-data="{ wizardStep: 0 }"
                    x-init="$nextTick(() => window.dispatchEvent(new CustomEvent('onboarding-step-request')))"
                    x-on:onboarding-step-changed.window="wizardStep = $event.detail.index"
                    x-show="wizardStep === 0"
                    x-cloak
                    class="mt-3 text-center"
                >
                    <a
                        data-testid="workspace-cancel-link"
                        href="{{ $cancelUrl }}"
                        wire:navigate
                        class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                    >
                        {{ $this->getCancelLabel() }}
                    </a>
                </div>
            @endif
        </div>

        {{-- Right: CRM preview (step-aware) --}}
        <div class="hidden w-[48%] shrink-0 border-s border-gray-950/5 bg-gray-50 lg:block dark:border-white/10 dark:bg-gray-950">
            <x-onboarding.crm-preview :preview="$this->getPreview()" panel="dashboard" :interactive="true" />
        </div>
    </div>
</x-onboarding.shell>
