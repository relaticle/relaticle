@props([
    /** @var array<int, array{0: string, 1: string}> [question, answer] pairs */
    'faqs',
    'idPrefix' => 'faq',
])

<div x-data="{ open: null }" class="divide-y divide-gray-200/80 dark:divide-white/[0.06]">
    @foreach($faqs as $index => [$question, $answer])
        <div class="faq-item">
            <h3>
                <button id="{{ $idPrefix }}-question-{{ $index }}"
                        @click="open = open === {{ $index }} ? null : {{ $index }}"
                        :aria-expanded="(open === {{ $index }}).toString()"
                        aria-expanded="false"
                        aria-controls="{{ $idPrefix }}-answer-{{ $index }}"
                        class="flex w-full cursor-pointer items-center justify-between gap-4 rounded-sm py-5 text-left transition-colors duration-150 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary dark:hover:text-primary-400">
                    <span class="text-base font-semibold text-gray-900 dark:text-white">
                        {{ $question }}
                    </span>
                    <x-lucide-chevron-down class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200 motion-reduce:transition-none" aria-hidden="true" ::class="open === {{ $index }} ? 'rotate-180' : ''"/>
                </button>
            </h3>
            <div id="{{ $idPrefix }}-answer-{{ $index }}" class="motion-reduce:transition-none!" x-show="open === {{ $index }}" x-collapse x-cloak>
                <div class="type-prose -mt-2 pb-5 text-gray-600 dark:text-gray-400">
                    {{ $answer }}
                </div>
            </div>
        </div>
    @endforeach
</div>
