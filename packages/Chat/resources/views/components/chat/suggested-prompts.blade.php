@props([
    'prompts' => [],
])

<div {{ $attributes->merge(['class' => 'flex flex-wrap justify-center gap-2']) }}>
    @foreach($prompts as $prompt)
        @php
            $label = is_array($prompt) ? $prompt['label'] : $prompt;
            $message = is_array($prompt) ? $prompt['prompt'] : $prompt;
        @endphp
        <button
            x-on:click="window.location.href = @js(\App\Filament\Pages\ChatConversation::getUrl()) + '?prompt=' + encodeURIComponent(@js($message))"
            class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 transition hover:border-primary-300 hover:bg-primary-50 hover:text-primary-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-primary-600 dark:hover:bg-primary-900/20 dark:hover:text-primary-400"
        >
            {{ $label }}
        </button>
    @endforeach
</div>
