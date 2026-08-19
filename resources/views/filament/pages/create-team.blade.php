<div class="flex min-h-full flex-col">

    {{-- Centered Relaticle logo --}}
    <div class="flex justify-center py-6">
        <x-brand.logo-lockup size="md" class="text-gray-900 dark:text-white" />
    </div>

    {{-- Main card --}}
    <div class="mx-auto w-full max-w-[960px] flex-1 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex h-full">
            {{-- Left: form, with a way back out for anyone who already has a workspace --}}
            <div class="flex flex-1 flex-col px-10 py-10 sm:px-12 sm:py-12">
                {{ $this->content }}

                @php($cancelUrl = $this->getCancelUrl())

                @if (filled($cancelUrl))
                    <div class="mt-3 text-center">
                        <a
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
            <div
                class="hidden w-[48%] shrink-0 bg-gray-50 lg:block dark:bg-gray-900/50"
                x-data="{ wizardStep: 0 }"
                x-on:onboarding-step-changed.window="wizardStep = $event.detail.index"
            >
                <x-onboarding.crm-preview
                    :use-case-labels="$this->getUseCaseLabelsForPreview()"
                />
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="flex items-center justify-center gap-x-1 py-6 text-xs text-gray-400 dark:text-gray-500">
        <span>&copy; {{ date('Y') }} Relaticle</span>
        <span>&middot;</span>
        <a href="{{ url('/privacy-policy') }}" class="hover:text-gray-600 dark:hover:text-gray-300">Privacy Policy</a>
        <span>&middot;</span>
        <a href="{{ url('/terms-of-service') }}" class="hover:text-gray-600 dark:hover:text-gray-300">Terms</a>
        <span>&middot;</span>
        <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="inline">
            @csrf
            <button type="submit" class="hover:text-gray-600 dark:hover:text-gray-300">Sign out</button>
        </form>
    </div>
</div>
