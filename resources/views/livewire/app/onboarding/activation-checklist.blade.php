{{-- Lives in the panel's sidebar footer, not on a page, so it follows the user
     into People or Opportunities instead of only existing on Home. --}}
<div>
    @if($this->visible)
        @php
            $steps = $this->steps;
            $total = count($steps);
            $completed = $this->completedCount;
        @endphp

        <div
            x-data="activationChecklist()"
            class="relative px-4 pb-2"
        >
            {{-- The expanded card floats above the pill rather than pushing the
                 sidebar taller, so opening it never scrolls the nav. --}}
            <div
                x-show="open"
                x-cloak
                x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 translate-y-1"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-on:keydown.escape.window="close()"
                class="absolute bottom-full left-2 right-2 z-20 mb-2 rounded-xl border border-gray-200 bg-white p-3 shadow-xl dark:border-white/10 dark:bg-gray-900"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ __('filament/pages/dashboard.activation.heading') }}
                        </h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            <span>{{ __('filament/pages/dashboard.activation.progress', ['completed' => $completed, 'total' => $total]) }}</span>
                            <span class="px-1 text-gray-300 dark:text-gray-600">|</span>
                            <span>{{ __('filament/pages/dashboard.activation.encouragement') }}</span>
                        </p>
                    </div>

                    <div class="relative flex flex-shrink-0 items-center gap-0.5">
                        <button
                            type="button"
                            x-on:click="menu = ! menu"
                            x-on:click.outside="menu = false"
                            :aria-expanded="menu ? 'true' : 'false'"
                            aria-label="{{ __('filament/pages/dashboard.activation.more_actions') }}"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200"
                        >
                            <x-heroicon-m-ellipsis-horizontal class="h-4 w-4" />
                        </button>

                        <button
                            type="button"
                            x-on:click="close()"
                            aria-label="{{ __('filament/pages/dashboard.activation.collapse') }}"
                            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/5 dark:hover:text-gray-200"
                        >
                            <x-heroicon-m-arrow-down-left class="h-4 w-4" />
                        </button>

                        {{-- Dismiss is the only item, so the menu is what makes it
                             discoverable without spending a row on it. --}}
                        <div
                            x-show="menu"
                            x-cloak
                            x-transition.opacity.duration.100ms
                            class="absolute right-0 top-full z-30 mt-1 w-max rounded-xl border border-gray-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-gray-900"
                        >
                            <button
                                type="button"
                                wire:click="dismiss"
                                class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                <x-heroicon-o-check-circle class="h-4 w-4 flex-shrink-0 text-gray-400" />
                                <span>{{ __('filament/pages/dashboard.activation.dismiss') }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- One segment per step: the progress reads at a glance without
                     the user parsing "1 of 4". --}}
                <div class="mt-2.5 flex gap-1" role="presentation">
                    @foreach($steps as $step)
                        <span @class([
                            'h-1 flex-1 rounded-full',
                            'bg-primary-600 dark:bg-primary-500' => $step->complete,
                            'bg-gray-200 dark:bg-white/10' => ! $step->complete,
                        ])></span>
                    @endforeach
                </div>

                @if($this->hasSampleData)
                    <p class="mt-2.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('filament/pages/dashboard.activation.sample_data') }}
                    </p>
                @endif

                <ul class="mt-1.5 -mx-1.5">
                    @foreach($steps as $step)
                        <li data-testid="activation-step" data-step="{{ $step->key }}" data-complete="{{ $step->complete ? 'true' : 'false' }}">
                            @php
                                $rowClasses = 'group flex w-full items-center gap-2.5 rounded-lg px-1.5 py-1.5 text-left transition hover:bg-gray-50 dark:hover:bg-white/5';
                            @endphp

                            @if($step->url !== null)
                                <a href="{{ $step->url }}" class="{{ $rowClasses }}">
                                    @include('livewire.app.onboarding.partials.step-body', ['step' => $step])
                                </a>
                            @else
                                {{-- No transcript to open yet. The chat page reads `?prompt=`
                                     and seeds its composer, which works from any page in the
                                     panel; the dashboard-only compose event did not. --}}
                                <a
                                    href="{{ \App\Filament\Pages\ChatConversation::getUrl(['prompt' => $step->prompt]) }}"
                                    class="{{ $rowClasses }}"
                                >
                                    @include('livewire.app.onboarding.partials.step-body', ['step' => $step])
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <button
                type="button"
                x-on:click="toggle()"
                :aria-expanded="open ? 'true' : 'false'"
                class="mx-auto flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-1.5 text-sm text-gray-700 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5"
            >
                <span class="font-medium">{{ __('filament/pages/dashboard.activation.heading') }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $completed }}/{{ $total }}</span>
            </button>
        </div>
    @endif

    @script
    <script>
        Alpine.data('activationChecklist', () => ({
            // Open until the user closes it themselves, then it stays closed.
            // It is a panel, not a popover: clicking elsewhere in the app does
            // not dismiss it, because a checklist that vanishes on the first
            // stray click is one the user cannot read while they work.
            // localStorage, not $persist: Filament does not bundle that plugin.
            open: true,
            menu: false,

            init() {
                const stored = this.read();

                this.open = stored === null ? true : stored;
            },

            read() {
                try {
                    const raw = localStorage.getItem('activation-checklist:open');

                    return raw === null ? null : raw === 'true';
                } catch (_) {
                    return null;
                }
            },

            write(value) {
                try {
                    localStorage.setItem('activation-checklist:open', value ? 'true' : 'false');
                } catch (_) { /* private mode, fall back to per-page state */ }
            },

            toggle() {
                this.open = ! this.open;
                this.menu = false;
                this.write(this.open);
            },

            close() {
                if (! this.open) return;

                this.open = false;
                this.menu = false;
                this.write(false);
            },
        }));
    </script>
    @endscript
</div>
