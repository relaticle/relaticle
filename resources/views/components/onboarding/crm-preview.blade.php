@props([
    'useCaseLabels' => [],
])

<div
    class="relative flex h-full flex-col overflow-hidden"
    x-data
>
    {{-- Tab bar (Attio-style) --}}
    <div class="flex items-end gap-4 border-b border-gray-200 px-5 pt-5 dark:border-white/10">
        {{-- Workspace tab (active) --}}
        <div class="flex items-center gap-2 border-b-2 border-gray-900 pb-3 dark:border-white">
            <div
                class="flex size-5 items-center justify-center rounded bg-gray-900 text-[9px] font-bold text-white dark:bg-white dark:text-gray-900"
                x-text="($wire.data?.name || 'W').charAt(0).toUpperCase()"
            ></div>
            <span
                class="max-w-[140px] truncate text-xs font-semibold text-gray-900 dark:text-white"
                x-text="$wire.data?.name || 'Workspace'"
                x-cloak
            ></span>
            <x-filament::icon icon="ri-arrow-down-s-line" class="size-3 text-gray-400" />
        </div>

        {{-- People tab (inactive) --}}
        <div class="flex items-center gap-1.5 border-b-2 border-transparent pb-3">
            <x-filament::icon icon="ri-user-line" class="size-3.5 text-gray-400" />
            <span class="text-xs text-gray-400">People</span>
        </div>
    </div>

    {{-- Toolbar --}}
    <div class="pointer-events-none select-none border-b border-gray-100 px-5 py-3 opacity-50 dark:border-white/5">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="size-5 rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-4 w-6 rounded bg-gray-100 dark:bg-gray-800"></div>
                <div class="h-4 w-4 rounded bg-gray-100 dark:bg-gray-800"></div>
            </div>
            <div class="flex items-center gap-2">
                <div class="h-5 w-14 rounded bg-primary-100 dark:bg-primary-900/30"></div>
                <div class="size-5 rounded bg-gray-100 dark:bg-gray-800"></div>
                <div class="size-5 rounded bg-gray-100 dark:bg-gray-800"></div>
            </div>
        </div>
    </div>

    {{-- Table skeleton --}}
    <div class="pointer-events-none flex-1 select-none px-5 pt-2 opacity-40">
        {{-- Filter chips --}}
        <div class="flex items-center gap-2 pb-3">
            <div class="h-5 w-12 rounded bg-gray-100 dark:bg-gray-800"></div>
            <div class="h-5 w-10 rounded bg-gray-100 dark:bg-gray-800"></div>
        </div>

        {{-- Rows --}}
        <div class="space-y-0">
            @for ($i = 0; $i < 10; $i++)
                <div class="flex items-center gap-3 border-b border-gray-100 py-2.5 dark:border-gray-800/50">
                    <div class="size-3 rounded bg-gray-100 dark:bg-gray-800"></div>
                    <div class="size-5 rounded-full bg-gray-200/70 dark:bg-gray-700"></div>
                    <div
                        @class([
                            'h-2.5 rounded',
                            'bg-gray-200 dark:bg-gray-700' => $i % 3 === 0,
                            'bg-gray-100 dark:bg-gray-800' => $i % 3 !== 0,
                        ])
                        style="width: {{ [55, 40, 70, 35, 60, 45, 50, 65, 38, 58][$i] }}%"
                    ></div>
                    <div class="ms-auto h-2.5 w-16 rounded bg-gray-100 dark:bg-gray-800"></div>
                </div>
            @endfor
        </div>
    </div>

    {{-- Contextual module preview (floating card, visible on use-case step) --}}
    <div
        x-show="wizardStep === 2 && $wire.data?.onboarding_use_case"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="absolute bottom-1/3 left-1/2 -translate-x-1/2 rounded-xl bg-white p-5 shadow-lg ring-1 ring-primary-200 dark:bg-gray-800 dark:ring-primary-500/30"
        x-cloak
    >
        <div class="space-y-3">
            <div class="flex items-center gap-2 text-sm">
                <x-filament::icon icon="ri-user-line" class="size-4 text-primary-500" />
                <span class="font-medium text-gray-900 dark:text-white">People</span>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <x-filament::icon icon="ri-building-line" class="size-4 text-primary-500" />
                <span class="font-medium text-gray-900 dark:text-white">Companies</span>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <x-filament::icon icon="ri-briefcase-line" class="size-4 text-primary-500" />
                <span
                    class="font-medium text-gray-900 dark:text-white"
                    x-text="(() => {
                        const labels = {{ Js::from($useCaseLabels) }};
                        return labels[$wire.data?.onboarding_use_case] || 'Opportunities';
                    })()"
                ></span>
            </div>
        </div>
    </div>

    {{-- Team invite preview (floating card, visible on invite step) --}}
    <div
        x-show="wizardStep === 3"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        class="absolute bottom-1/3 left-1/2 -translate-x-1/2 rounded-xl bg-white p-5 shadow-lg ring-1 ring-primary-200 dark:bg-gray-800 dark:ring-primary-500/30"
        x-cloak
    >
        <div class="space-y-3">
            <div class="flex -space-x-2">
                <div class="flex size-8 items-center justify-center rounded-full bg-primary-100 ring-2 ring-white dark:bg-primary-900/30 dark:ring-gray-800">
                    <x-filament::icon icon="ri-user-fill" class="size-4 text-primary-500" />
                </div>
                <div class="flex size-8 items-center justify-center rounded-full bg-primary-100 ring-2 ring-white dark:bg-primary-900/30 dark:ring-gray-800">
                    <x-filament::icon icon="ri-user-fill" class="size-4 text-primary-500" />
                </div>
                <div class="flex size-8 items-center justify-center rounded-full bg-gray-100 ring-2 ring-white dark:bg-gray-700 dark:ring-gray-800">
                    <x-filament::icon icon="ri-add-line" class="size-4 text-gray-400" />
                </div>
            </div>
            <p class="text-sm font-medium text-gray-900 dark:text-white">Invite teammates</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">Collaborate in real-time</p>
        </div>
    </div>
</div>
