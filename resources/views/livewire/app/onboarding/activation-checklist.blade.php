<div>
    @if($this->visible)
        @php
            $steps = $this->steps;
            $total = count($steps);
            $completed = $this->completedCount;
        @endphp

        <div class="mt-14">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="flex items-baseline gap-2 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <span>{{ __('filament/pages/dashboard.activation.heading') }}</span>
                    <span class="text-gray-400 dark:text-gray-500">
                        {{ __('filament/pages/dashboard.activation.progress', ['completed' => $completed, 'total' => $total]) }}
                    </span>
                </h2>

                <button
                    type="button"
                    wire:click="dismiss"
                    class="text-xs text-gray-500 transition hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                >
                    {{ __('filament/pages/dashboard.activation.dismiss') }}
                </button>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                @if($this->hasSampleData)
                    <p class="border-b border-gray-100 px-4 py-3 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        {{ __('filament/pages/dashboard.activation.sample_data') }}
                    </p>
                @endif

                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($steps as $step)
                        <li data-testid="activation-step" data-step="{{ $step->key }}" data-complete="{{ $step->complete ? 'true' : 'false' }}">
                            <a
                                href="{{ $step->url }}"
                                class="flex items-center gap-3 px-4 py-3 transition hover:bg-gray-50 dark:hover:bg-gray-700/50"
                            >
                                @if($step->complete)
                                    <x-heroicon-s-check-circle class="h-5 w-5 flex-shrink-0 text-primary-600 dark:text-primary-400" />
                                @else
                                    {{ svg($step->icon, 'h-5 w-5 flex-shrink-0 text-gray-400 dark:text-gray-500') }}
                                @endif

                                <span class="flex-1">
                                    <span @class([
                                        'block text-sm',
                                        'text-gray-500 line-through dark:text-gray-500' => $step->complete,
                                        'font-medium text-gray-900 dark:text-white' => ! $step->complete,
                                    ])>
                                        {{ $step->label }}
                                    </span>

                                    @unless($step->complete)
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                            {{ $step->description }}
                                        </span>
                                    @endunless
                                </span>

                                @unless($step->complete)
                                    <x-heroicon-o-chevron-right class="h-4 w-4 flex-shrink-0 text-gray-400 dark:text-gray-500" />
                                @endunless
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
