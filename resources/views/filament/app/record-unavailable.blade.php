<div class="fi-record-unavailable flex min-h-[60dvh] flex-col items-center justify-center px-6 py-16">
    <x-filament::empty-state
        :heading="$heading"
        :description="$body"
        :icon="$icon"
        icon-color="gray"
        :contained="false"
    >
        <x-slot:footer>
            <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                <x-filament::button tag="a" :href="$actionUrl" :icon="$actionIcon">
                    {{ $actionLabel }}
                </x-filament::button>

                @if (filled($helpUrl))
                    <x-filament::link tag="a" :href="$helpUrl" target="_blank" icon="lucide-circle-help">
                        {{ __('filament/record-page.unavailable.merged.help') }}
                    </x-filament::link>
                @endif
            </div>
        </x-slot:footer>
    </x-filament::empty-state>
</div>
