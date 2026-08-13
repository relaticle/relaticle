@props([
    /** @var array<int, array{0: string, 1: string}> [question, answer] pairs */
    'faqs',
    'idPrefix' => 'faq',
])

<div x-data="{ open: null }" class="divide-y divide-gray-200/80 dark:divide-white/[0.06]">
    @foreach($faqs as $index => [$question, $answer])
        <div class="faq-item py-5">
            <button id="{{ $idPrefix }}-question-{{ $index }}"
                    @click="open = open === {{ $index }} ? null : {{ $index }}"
                    :aria-expanded="(open === {{ $index }}).toString()"
                    aria-controls="{{ $idPrefix }}-answer-{{ $index }}"
                    class="flex w-full items-center justify-between text-left gap-4 cursor-pointer hover:text-primary dark:hover:text-primary-400 transition-colors duration-150">
                <span class="text-base font-semibold text-gray-900 dark:text-white">
                    {{ $question }}
                </span>
                <x-ri-arrow-down-s-line class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" ::class="open === {{ $index }} ? 'rotate-180' : ''"/>
            </button>
            <div id="{{ $idPrefix }}-answer-{{ $index }}" role="region" aria-labelledby="{{ $idPrefix }}-question-{{ $index }}" x-show="open === {{ $index }}" x-collapse x-cloak class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                {{ $answer }}
            </div>
        </div>
    @endforeach
</div>
