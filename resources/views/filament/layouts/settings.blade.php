@php
    $livewire ??= null;
@endphp

{{-- Account settings drop the panel shell, so the topbar is rebuilt here. It
     keeps a .fi-topbar-start: the app panel teleports every page heading there
     (see filament.app.topbar-page-heading), and without the target Alpine
     aborts the teleport and the page renders with no heading at all. --}}
<x-filament-panels::layout.base :livewire="$livewire">
    <a href="#fi-main-content" class="fi-skip-link fi-sr-only">
        {{ __('filament-panels::layout.skip_to_content.label') }}
    </a>

    <div class="fi-settings-layout">
        <header class="fi-settings-topbar">
            <div class="fi-settings-container">
                <a
                    href="{{ \App\Filament\Pages\Dashboard::getUrl() }}"
                    wire:navigate
                    class="fi-settings-back-link"
                >
                    <x-filament::icon icon="ri-arrow-left-line" class="size-4" />
                    {{ __('filament/panel.settings_layout.back_to_app') }}
                </a>

                <div class="fi-topbar-start"></div>
            </div>
        </header>

        <main id="fi-main-content" class="fi-settings-main">
            {{ $slot }}
        </main>
    </div>
</x-filament-panels::layout.base>
