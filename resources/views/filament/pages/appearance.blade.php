@php
    $themeIcons = [
        'light' => 'ri-sun-line',
        'dark' => 'ri-moon-line',
        'system' => 'ri-computer-line',
    ];
@endphp

<x-filament-panels::page>
    <div
        x-cloak
        x-data="{
            theme: localStorage.getItem('theme') ?? @js(\Filament\Facades\Filament::getDefaultThemeMode()->value),
            accent: localStorage.getItem('accent'),

            selectTheme(theme) {
                this.theme = theme
                localStorage.setItem('theme', theme)

                document.documentElement.classList.toggle(
                    'dark',
                    theme === 'dark' ||
                        (theme === 'system' &&
                            window.matchMedia('(prefers-color-scheme: dark)').matches),
                )
            },

            selectAccent(accent) {
                this.accent = accent

                if (accent) {
                    localStorage.setItem('accent', accent)
                    document.documentElement.dataset.accent = accent
                } else {
                    localStorage.removeItem('accent')
                    delete document.documentElement.dataset.accent
                }
            },
        }"
        class="space-y-6"
    >
        <x-filament::section
            :heading="__('appearance.theme.heading')"
            :description="__('appearance.theme.description')"
        >
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach($themes as $themeOption)
                    <button
                        type="button"
                        @click="selectTheme(@js($themeOption->value))"
                        :aria-pressed="theme === @js($themeOption->value)"
                        class="group flex flex-col gap-3 rounded-xl border p-3 text-left transition"
                        :class="theme === @js($themeOption->value)
                            ? 'border-primary-600 ring-1 ring-primary-600'
                            : 'border-gray-200 hover:border-gray-300 dark:border-white/10 dark:hover:border-white/20'"
                    >
                        <x-app.theme-preview :mode="$themeOption->value" />

                        <span class="flex items-center gap-2 px-1 pb-1 text-sm font-medium text-gray-950 dark:text-white">
                            <x-filament::icon :icon="$themeIcons[$themeOption->value]" class="size-4" />
                            {{ __('appearance.theme.modes.'.$themeOption->value) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section
            :heading="__('appearance.accent.heading')"
            :description="__('appearance.accent.description')"
        >
            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    @click="selectAccent(null)"
                    :aria-pressed="accent === null"
                    title="{{ __('appearance.accent.brand_default') }}"
                    class="size-7 rounded-full transition"
                    style="background-color: {{ $brandColor }}; --tw-ring-color: {{ $brandColor }};"
                    :class="accent === null
                        ? 'ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900'
                        : 'hover:scale-110'"
                >
                    <span class="sr-only">{{ __('appearance.accent.brand_default') }}</span>
                </button>

                @foreach($accents as $accent)
                    <button
                        type="button"
                        @click="selectAccent(@js($accent->name))"
                        :aria-pressed="accent === @js($accent->name)"
                        title="{{ $accent->label() }}"
                        class="size-7 rounded-full transition"
                        style="background-color: {{ $accent->value }}; --tw-ring-color: {{ $accent->value }};"
                        :class="accent === @js($accent->name)
                            ? 'ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900'
                            : 'hover:scale-110'"
                    >
                        <span class="sr-only">{{ $accent->label() }}</span>
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
