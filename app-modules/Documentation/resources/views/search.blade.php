<x-documentation::layout :document="[
    'title' => 'Search Results for: ' . $query
]">
    <div class="max-w-4xl mx-auto">

        {{-- Back link --}}
        <div class="mb-8 flex justify-center">
            <a href="{{ route('documentation.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors">
                <x-ri-arrow-left-line class="w-3.5 h-3.5"/>
                Back to documentation
            </a>
        </div>

        {{-- Header --}}
        <div class="text-center max-w-2xl mx-auto mb-12">
            <h1 class="font-display text-4xl sm:text-5xl font-bold text-gray-950 dark:text-white leading-[1.1] tracking-[-0.02em]">
                Search Results
            </h1>
            @if($results->isNotEmpty())
                <p class="mt-5 text-base text-gray-500 dark:text-gray-400">
                    {{ $results->count() }} {{ Str::plural('result', $results->count()) }} for "{{ $query }}"
                </p>
            @endif
        </div>

        {{-- Search form --}}
        <div class="mb-14 max-w-lg mx-auto">
            <x-documentation::search-form :query="$query" />
        </div>

        @if($results->isEmpty())
            {{-- Empty state --}}
            <div class="text-center max-w-md mx-auto">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-gray-100 dark:bg-white/[0.04] mb-4">
                    <x-ri-search-line class="w-5 h-5 text-gray-400 dark:text-gray-500"/>
                </div>

                <h2 class="font-display text-lg font-semibold text-gray-900 dark:text-white mb-2">
                    No matches found
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-8">
                    We couldn't find anything matching "<span class="font-medium text-gray-700 dark:text-gray-300">{{ $query }}</span>"
                </p>

                <div class="text-left text-sm text-gray-500 dark:text-gray-400 space-y-2">
                    <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">Search tips</p>
                    <div class="flex items-center gap-2">
                        <x-ri-check-line class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 shrink-0"/>
                        Try simpler or alternate terms
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ri-check-line class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 shrink-0"/>
                        Check spelling of search terms
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ri-check-line class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 shrink-0"/>
                        Use fewer keywords to broaden results
                    </div>
                </div>
            </div>
        @else
            {{-- Results --}}
            <div class="border-t border-gray-200/60 dark:border-white/[0.04] divide-y divide-gray-200/60 dark:divide-white/[0.04]">
                @foreach($results as $result)
                    <a href="{{ $result->url }}" class="group block p-6 transition-colors duration-200 hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1.5">
                                    <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wider">
                                        {{ $documentTypes[$result->type]['title'] }}
                                    </span>
                                </div>
                                <h2 class="font-display text-[15px] font-semibold text-gray-900 dark:text-white mb-1.5">
                                    {{ $result->title }}
                                </h2>
                                <p class="text-[13px] leading-relaxed text-gray-500 dark:text-gray-400 line-clamp-2">
                                    {!! strip_tags($result->excerpt, '<mark>') !!}
                                </p>
                            </div>
                            <x-ri-arrow-right-line class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600 group-hover:text-gray-400 group-hover:translate-x-0.5 transition-all duration-200 shrink-0 mt-1"/>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-documentation::layout>
