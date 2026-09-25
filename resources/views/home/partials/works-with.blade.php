@php
    $clients = [
        ['ri-claude-fill', 'text-[#D4763C]', 'Claude'],
        ['ri-openai-fill', 'text-gray-900 dark:text-gray-200', 'ChatGPT'],
        ['ri-cursor-ai-fill', 'text-gray-900 dark:text-gray-200', 'Cursor'],
        ['ri-gemini-fill', 'text-blue-500', 'Gemini'],
    ];
@endphp

<section aria-label="{{ __('Works with') }}" class="relative bg-white dark:bg-gray-950 border-y border-gray-200 dark:border-white/[0.08]">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-[2fr_repeat(4,1fr)_1.5fr] divide-x divide-y md:divide-y-0 divide-gray-200 dark:divide-white/[0.08] border-x border-gray-200 dark:border-white/[0.08]">
            <div class="col-span-2 md:col-span-1 flex items-center px-5 py-4 md:py-6">
                <p class="font-mono uppercase tracking-[0.14em] text-[10px] leading-relaxed text-gray-500 dark:text-gray-400">
                    {{ __('Works with the agents your team already uses') }}
                </p>
            </div>

            @foreach($clients as [$icon, $color, $name])
                <div class="flex items-center justify-center gap-2.5 px-4 py-5 md:py-6">
                    <x-dynamic-component :component="$icon" class="w-5 h-5 shrink-0 {{ $color }}"/>
                    <span class="text-sm font-semibold tracking-tight text-gray-800 dark:text-gray-200">{{ $name }}</span>
                </div>
            @endforeach

            @if(\Laravel\Pennant\Feature::active(\App\Features\Documentation::class))
                <a href="{{ route('documentation.index') }}" class="group col-span-2 md:col-span-1 flex items-center justify-center gap-1.5 px-3 py-5 md:py-6 whitespace-nowrap text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                    {{ __('+ any MCP client') }}
                    <x-ri-arrow-right-up-line class="w-3.5 h-3.5 text-gray-400 dark:text-gray-500 group-hover:text-current transition-colors"/>
                </a>
            @else
                <div class="col-span-2 md:col-span-1 flex items-center justify-center px-3 py-5 md:py-6 whitespace-nowrap text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('+ any MCP client') }}
                </div>
            @endif
        </div>

        @if($formattedDockerPulls !== null)
            <p class="border-x border-t border-gray-200 dark:border-white/[0.08] px-5 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $formattedDockerPulls }}</span> {{ __('Docker pulls') }}
                <span class="mx-2 text-gray-300 dark:text-gray-600">/</span>{{ __('self-hosted, AGPL-3.0, shipping weekly') }}
            </p>
        @endif
    </div>
</section>
