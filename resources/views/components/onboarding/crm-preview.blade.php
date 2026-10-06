@props([
    'preview',
    'panel' => 'dashboard',
    'interactive' => false,
])

@php
    $hasStages = $preview['stages'] !== [];
    $idleNavigationKey = $panel === 'people' ? 'people' : 'dashboard';
    $barWidths = ['dashboard' => 'w-10', 'people' => 'w-12', 'companies' => 'w-16', 'opportunities' => 'w-20', 'tasks' => 'w-9', 'notes' => 'w-11', 'emails' => 'w-12'];
@endphp

<div
    class="relative h-full overflow-hidden"
    x-data="{
        get showBoard() {
            return @js($hasStages && $panel === 'board')
        },
        get spotlight() {
            return @js($interactive) && wizardStep === 0 ? (focused ?? hovered) : null
        },
        lens(region) {
            if (this.spotlight === region) {
                return 'relative z-10 scale-110 bg-white shadow-xl shadow-primary-950/10 ring-1 ring-primary-500/40 dark:bg-gray-800 dark:ring-primary-400/40'
            }

            return this.spotlight ? 'opacity-40' : ''
        },
        dim() {
            return this.spotlight ? 'opacity-40' : ''
        },
    }"
    aria-hidden="true"
>
    <div
        class="pointer-events-none absolute end-0 top-16 bottom-0 flex select-none rounded-tl-2xl bg-white shadow-2xl shadow-gray-950/10 ring-1 ring-gray-950/5 transition-[inset] duration-500 ease-out dark:bg-gray-900 dark:shadow-black/40 dark:ring-white/10"
        x-bind:class="showBoard ? 'start-6' : 'start-14'"
    >
        <div class="flex w-40 shrink-0 flex-col gap-y-3 border-e border-gray-950/5 p-3 dark:border-white/10">
            <div
                class="flex origin-left items-center gap-2 rounded-lg p-1.5 transition duration-300 ease-out"
                x-bind:class="[lens('workspace'), spotlight === 'workspace' ? 'w-max min-w-full pe-3' : 'min-w-0']"
            >
                <img
                    src="{{ $preview['workspaceAvatarUrl'] }}"
                    alt=""
                    class="size-6 shrink-0 rounded-md object-cover"
                />
                <span
                    class="text-sm font-semibold text-gray-950 dark:text-white"
                    x-bind:class="spotlight === 'workspace' ? 'whitespace-nowrap' : 'truncate'"
                    @if ($interactive)
                        x-text="$wire.data?.name || @js($preview['companyPlaceholder'])"
                    @endif
                >{{ $preview['workspaceName'] }}</span>
                <x-filament::icon icon="ri-arrow-down-s-line" class="ms-auto size-4 shrink-0 text-gray-400" />
            </div>

            <div class="flex flex-col gap-y-3 transition duration-300" x-bind:class="dim()">
                <div class="flex items-center gap-2 rounded-md px-2 py-1.5 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="size-3.5 shrink-0 text-gray-400" />
                    <span class="h-1.5 w-14 rounded-full bg-gray-100 dark:bg-white/10"></span>
                    <span class="ms-auto h-3.5 w-6 rounded bg-gray-100 dark:bg-white/10"></span>
                </div>

                <div class="space-y-0.5">
                    @foreach ($preview['navigationIcons'] as $key => $icon)
                        <div
                            class="flex items-center gap-2.5 rounded-md px-2 py-1.5 transition-colors duration-300"
                            x-bind:class="(showBoard ? 'opportunities' : @js($idleNavigationKey)) === @js($key) ? 'bg-gray-100 text-primary-600 dark:bg-white/5 dark:text-primary-400' : 'text-gray-400 dark:text-gray-500'"
                        >
                            <x-filament::icon :icon="$icon" class="size-4 shrink-0" />
                            <span @class(['h-1.5 rounded-full bg-current opacity-25', $barWidths[$key] ?? 'w-12'])></span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="relative flex min-w-0 flex-1 flex-col overflow-hidden bg-gray-50/60 dark:bg-gray-950/40">
            <div class="flex h-12 shrink-0 items-center justify-end border-b border-gray-950/5 px-4 dark:border-white/10">
                <img
                    src="{{ $preview['userAvatarUrl'] }}"
                    alt=""
                    class="size-6 rounded-full object-cover transition duration-300 ease-out"
                    x-bind:class="lens('user')"
                />
            </div>

            @if ($panel !== 'people')
                <div
                    class="flex flex-1 flex-col px-5 pt-10"
                    x-show="! showBoard"
                    x-transition.opacity.duration.300ms
                >
                    <p
                        class="self-center rounded-lg px-2 py-1 text-center text-base font-semibold tracking-tight text-gray-950 transition duration-300 ease-out dark:text-white"
                        x-bind:class="lens('user')"
                    >{{ $preview['greeting'] }}</p>

                    <div class="mt-4 transition duration-300" x-bind:class="dim()">
                        <div class="rounded-xl bg-white p-3 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                            <span class="block h-1.5 w-20 rounded-full bg-gray-100 dark:bg-white/10"></span>
                            <div class="mt-6 flex justify-end">
                                <span class="size-5 rounded-full bg-gray-100 dark:bg-white/10"></span>
                            </div>
                        </div>

                        <span class="mt-6 block h-1.5 w-10 rounded-full bg-gray-200 dark:bg-white/15"></span>

                        <div class="mt-3 space-y-3">
                            @foreach (['w-3/4', 'w-1/2', 'w-2/3'] as $width)
                                <div class="flex items-center gap-2">
                                    <span class="size-3 shrink-0 rounded-full ring-1 ring-gray-300 dark:ring-gray-600"></span>
                                    <span @class(['h-1.5 rounded-full bg-gray-100 dark:bg-white/10', $width])></span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if ($panel === 'people')
                <div class="flex flex-1 flex-col px-3 pt-4">
                    <div class="flex items-center justify-between px-0.5">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('filament/pages/workspaces.setup_workspace.preview.people') }}</span>
                        <span class="flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-[10px] font-medium text-gray-500 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:text-gray-400 dark:ring-white/10">
                            @if (isset($preview['mailboxChip']))
                                <span class="size-1.5 rounded-full bg-success-500"></span>
                            @else
                                <x-filament::icon icon="ri-mail-line" class="size-3" />
                            @endif
                            {{ $preview['mailboxChip'] ?? __('filament/pages/workspaces.setup_workspace.preview.from_mailbox') }}
                        </span>
                    </div>

                    <div class="mt-3 overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                        <div class="grid grid-cols-[1.3fr_1fr] gap-x-2 border-b border-gray-950/10 px-2.5 py-1.5 text-[10px] font-medium text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <span>{{ __('filament/pages/workspaces.setup_workspace.preview.person') }}</span>
                            <span>{{ __('filament/pages/workspaces.setup_workspace.preview.company') }}</span>
                        </div>

                        @foreach ([['w-18', 'w-14'], ['w-14', 'w-12'], ['w-16', 'w-15'], ['w-12', 'w-10'], ['w-18', 'w-12'], ['w-14', 'w-14'], ['w-16', 'w-11'], ['w-13', 'w-12']] as $index => [$personWidth, $companyWidth])
                            <div
                                @class(['grid grid-cols-[1.3fr_1fr] items-center gap-x-2 px-2.5 py-2', 'border-t border-gray-950/10 dark:border-white/10' => $index > 0])
                                style="opacity: {{ max(0.25, 1 - $index * 0.11) }}"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span class="size-4 shrink-0 rounded-full bg-gray-200 dark:bg-white/15"></span>
                                    <span @class(['h-1.5 rounded-full bg-gray-200 dark:bg-white/15', $personWidth])></span>
                                </div>
                                <span @class(['h-1.5 rounded-full bg-gray-100 dark:bg-white/10', $companyWidth])></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($hasStages)
                <div
                    class="flex flex-1 gap-2 p-3"
                    x-show="showBoard"
                    x-cloak
                >
                    @foreach ($preview['stages'] as $position => $stage)
                        <div
                            class="w-[7.25rem] shrink-0 self-start rounded-xl bg-gray-100/80 p-1.5 dark:bg-white/5"
                            x-show="showBoard"
                            x-transition:enter="transition duration-500 ease-out"
                            x-transition:enter-start="translate-y-3 opacity-0"
                            x-transition:enter-end="translate-y-0 opacity-100"
                            style="transition-delay: {{ min($position, 6) * 70 }}ms"
                        >
                            <div class="flex items-center gap-1.5 px-1.5 py-1">
                                <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $stage['color'] }}"></span>
                                <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-200">{{ $stage['name'] }}</span>
                            </div>

                            @for ($card = 0; $card < max(1, 3 - $position); $card++)
                                <div class="mt-1.5 rounded-lg bg-white p-2.5 shadow-xs ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                    <span class="block h-1.5 w-3/4 rounded-full bg-gray-200 dark:bg-white/15"></span>
                                    <span class="mt-2 block h-1.5 w-1/2 rounded-full bg-gray-100 dark:bg-white/5"></span>
                                </div>
                            @endfor
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="pointer-events-none absolute inset-x-0 bottom-0 z-30 h-28 bg-gradient-to-t from-gray-50 to-transparent dark:from-gray-950"></div>
</div>
