@props(['size' => 'sm'])

@php
    $iconSize = match($size) {
        'md' => 'h-4 w-4',
        default => 'h-3.5 w-3.5',
    };

    $buttonClass = 'p-1.5 rounded-full transition-all duration-200 cursor-pointer text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 aria-pressed:bg-white aria-pressed:dark:bg-gray-700 aria-pressed:shadow-sm aria-pressed:text-gray-900 aria-pressed:dark:text-white';
@endphp

<div x-data="{
        theme: localStorage.theme || 'system',
        apply(mode) {
            this.theme = mode;

            if (mode === 'system') {
                localStorage.removeItem('theme');
                document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
            } else {
                localStorage.theme = mode;
                document.documentElement.classList.toggle('dark', mode === 'dark');
            }

            window.dispatchEvent(new CustomEvent('theme-changed', { detail: mode }));
        }
     }"
     x-init="
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (theme === 'system') { apply('system'); }
        });
     "
     @theme-changed.window="theme = $event.detail"
     class="inline-flex items-center rounded-full border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900 p-0.5"
>
    <button @click="apply('system')" :aria-pressed="theme === 'system'" aria-label="System theme" class="{{ $buttonClass }}">
        <x-heroicon-o-computer-desktop class="{{ $iconSize }}" />
    </button>
    <button @click="apply('light')" :aria-pressed="theme === 'light'" aria-label="Light theme" class="{{ $buttonClass }}">
        <x-heroicon-o-sun class="{{ $iconSize }}" />
    </button>
    <button @click="apply('dark')" :aria-pressed="theme === 'dark'" aria-label="Dark theme" class="{{ $buttonClass }}">
        <x-heroicon-o-moon class="{{ $iconSize }}" />
    </button>
</div>
