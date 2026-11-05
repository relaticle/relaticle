@php
    $checkCount = count($checks);
@endphp

<div
    class="feat-card bg-hero-panel border-rail border-t px-3 py-6 sm:px-8 md:py-12"
    data-ai-native-checklist
    x-data="{
        marks: Array(@js($checkCount)).fill(false),
        get score() { return this.marks.filter(Boolean).length },
    }"
>
    <x-cloud::marketing.window class="mx-auto w-full max-w-5xl">
        <div class="border-rail flex flex-wrap items-center gap-x-6 gap-y-2 border-b px-5 py-3.5 md:px-6">
            <span class="type-label text-gray-500 dark:text-gray-400">{{ __('AI-native checklist') }}</span>

            <p class="ml-auto text-sm text-gray-700 dark:text-gray-300" aria-live="polite" data-ai-native-score>
                {{ __('Your CRM') }}
                <span class="font-semibold tabular-nums text-gray-950 dark:text-white"><span x-text="score">0</span> {{ __('of :count', ['count' => $checkCount]) }}</span>
                <span class="text-gray-400 dark:text-gray-500" aria-hidden="true">·</span>
                {{ __('Relaticle') }}
                <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ __(':count of :count', ['count' => $checkCount]) }}</span>
            </p>
        </div>

        <div class="divide-rail divide-y">
            <div class="hidden grid-cols-12 gap-6 px-5 py-2.5 md:grid md:px-6" aria-hidden="true">
                <span class="type-label col-span-6 text-gray-500 dark:text-gray-400">{{ __('The check') }}</span>
                <span class="type-label col-span-2 text-gray-500 dark:text-gray-400">{{ __('Your CRM') }}</span>
                <span class="type-label col-span-4 text-gray-500 dark:text-gray-400">{{ __('Relaticle') }}</span>
            </div>

            @foreach($checks as $index => [$question, $why, $answer, $proofLabel, $proofHref])
                <div class="grid grid-cols-1 gap-4 px-5 py-5 md:grid-cols-12 md:gap-6 md:px-6" data-ai-native-check>
                    <div class="md:col-span-6">
                        <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $question }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $why }}</p>
                    </div>

                    <div class="md:col-span-2">
                        <button
                            type="button"
                            x-on:click="marks[{{ $index }}] = ! marks[{{ $index }}]"
                            x-bind:aria-pressed="marks[{{ $index }}].toString()"
                            aria-pressed="false"
                            aria-label="{{ __('My CRM passes: :check', ['check' => $question]) }}"
                            class="border-rail inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm font-medium text-gray-700 transition-colors hover:border-gray-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary aria-pressed:border-primary aria-pressed:bg-primary/[0.06] aria-pressed:text-primary dark:text-gray-300 dark:hover:border-white/20 dark:aria-pressed:bg-primary/15 dark:aria-pressed:text-primary-300"
                            data-ai-native-toggle
                        >
                            <span class="border-rail flex size-4 items-center justify-center rounded border bg-surface" aria-hidden="true">
                                <x-ri-check-line class="size-3" x-show="marks[{{ $index }}]" x-cloak/>
                            </span>
                            <span class="md:hidden">{{ __('Your CRM') }}</span>
                            <span x-text="marks[{{ $index }}] ? @js(__('Yes')) : @js(__('Not yet'))">{{ __('Not yet') }}</span>
                        </button>
                    </div>

                    <div class="md:col-span-4">
                        <p class="flex items-start gap-2 text-sm leading-relaxed text-gray-700 dark:text-gray-300">
                            <span class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded bg-emerald-600 text-white" aria-hidden="true">
                                <x-ri-check-line class="size-3"/>
                            </span>
                            <span><span class="sr-only">{{ __('Yes.') }} </span>{{ $answer }}</span>
                        </p>
                        <a href="{{ $proofHref }}" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">
                            {{ $proofLabel }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </x-cloud::marketing.window>
</div>
