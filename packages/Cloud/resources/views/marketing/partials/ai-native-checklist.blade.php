@php
    $checkCount = count($checks);
    $rowGrid = 'grid grid-cols-[1.5rem_minmax(0,1fr)] gap-x-4 md:grid-cols-[1.5rem_minmax(0,1fr)_minmax(0,1fr)] md:gap-x-8';
@endphp

<div
    class="feat-card bg-hero-panel border-rail border-t px-3 py-6 sm:px-8 md:py-12"
    data-ai-native-checklist
    x-data="{
        marks: Array(@js($checkCount)).fill(false),
        get score() { return this.marks.filter(Boolean).length },
        toggle(index) { this.marks[index] = ! this.marks[index] },
    }"
>
    <x-cloud::marketing.window class="mx-auto w-full max-w-5xl">
        <div class="border-rail flex flex-wrap items-center justify-between gap-x-8 gap-y-3 border-b px-5 py-4 md:px-6">
            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span class="type-label text-gray-500 dark:text-gray-400">{{ __('AI-native checklist') }}</span>
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('Tick what your CRM does today.') }}</span>
            </div>

            <p class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-gray-700 dark:text-gray-300" aria-live="polite" data-ai-native-score>
                <span class="inline-flex items-center gap-2.5">
                    <span class="w-16 sm:w-auto">{{ __('Your CRM') }}</span>
                    <span class="flex gap-1" aria-hidden="true">
                        @for($segment = 0; $segment < $checkCount; $segment++)
                            <span
                                class="h-1.5 w-4 rounded-full transition-colors"
                                x-bind:class="score > {{ $segment }} ? 'bg-primary' : 'bg-gray-200 dark:bg-white/15'"
                            ></span>
                        @endfor
                    </span>
                    <span class="font-semibold tabular-nums text-gray-950 dark:text-white"><span x-text="score" data-ai-native-yours>0</span> {{ __('of :count', ['count' => $checkCount]) }}</span>
                </span>
                <span class="hidden text-gray-400 sm:inline dark:text-gray-500" aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-2.5">
                    <span class="w-16 sm:w-auto">{{ __('Relaticle') }}</span>
                    <span class="flex gap-1" aria-hidden="true">
                        @for($segment = 0; $segment < $checkCount; $segment++)
                            <span class="h-1.5 w-4 rounded-full bg-emerald-600"></span>
                        @endfor
                    </span>
                    <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ __(':count of :count', ['count' => $checkCount]) }}</span>
                </span>
            </p>
        </div>

        <div class="divide-rail divide-y">
            <div class="{{ $rowGrid }} hidden px-5 py-2.5 md:grid md:px-6" aria-hidden="true">
                <span class="type-label col-start-2 text-gray-500 dark:text-gray-400">{{ __('The check') }}</span>
                <span class="type-label text-gray-500 dark:text-gray-400">{{ __('Relaticle') }}</span>
            </div>

            @foreach($checks as $index => [$question, $why, $answer, $proofLabel, $proofHref])
                <div class="{{ $rowGrid }} gap-y-3 px-5 py-4 md:px-6 md:py-5" data-ai-native-check>
                    <button
                        type="button"
                        x-on:click="toggle({{ $index }})"
                        x-bind:aria-pressed="marks[{{ $index }}].toString()"
                        aria-pressed="false"
                        aria-label="{{ __('My CRM passes: :check', ['check' => $question]) }}"
                        class="bg-surface mt-px flex size-6 cursor-pointer items-center justify-center rounded-md border border-gray-500 text-white transition-colors hover:border-gray-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-pressed:border-primary aria-pressed:bg-primary dark:border-gray-500 dark:hover:border-white dark:aria-pressed:border-primary"
                        data-ai-native-toggle
                    >
                        <x-ri-check-line class="size-4" aria-hidden="true" x-show="marks[{{ $index }}]" x-cloak/>
                        <span class="sr-only" x-text="marks[{{ $index }}] ? @js(__('Yes')) : @js(__('Not yet'))">{{ __('Not yet') }}</span>
                    </button>

                    <div class="cursor-pointer" x-on:click="toggle({{ $index }})">
                        <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $question }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $why }}</p>
                    </div>

                    <div class="col-start-2 md:col-start-3">
                        <span class="type-label mb-1.5 block text-gray-500 md:hidden dark:text-gray-400">{{ __('Relaticle') }}</span>
                        <p class="flex items-start gap-2.5 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                            <span class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded bg-emerald-600 text-white" aria-hidden="true">
                                <x-ri-check-line class="size-3"/>
                            </span>
                            <span>
                                <span class="sr-only">{{ __('Yes.') }} </span>{{ $answer }}
                                <a href="{{ $proofHref }}" class="inline-flex items-center gap-0.5 font-medium whitespace-nowrap text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ $proofLabel }}<x-ri-arrow-right-line class="size-3.5" aria-hidden="true"/></a>
                            </span>
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.window>
</div>
