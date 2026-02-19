<x-documentation::layout :document="[
    'title' => 'Search Results for: ' . $query
]">
    <div class="max-w-5xl mx-auto px-4 sm:px-6">

        <!-- Back link -->
        <div class="mb-8 flex justify-center">
            <a href="{{ route('documentation.index') }}" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 transition-colors">
                <svg class="mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M9.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L7.414 9H15a1 1 0 110 2H7.414l2.293 2.293a1 1 0 010 1.414z" clip-rule="evenodd" />
                </svg>
                Back to documentation
            </a>
        </div>

        <!-- Search header -->
        <div class="text-center space-y-5 mb-12">
            <h1 class="font-display text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white leading-tight tracking-tight">
                <span class="relative inline-block">
                    <span class="relative z-10">Search Results</span>
                    <span class="absolute bottom-1 left-0 w-full h-3 bg-primary-100 dark:bg-primary-800/40 -rotate-1 z-0"></span>
                </span>
            </h1>

            @if($results->isNotEmpty())
                <p class="text-base text-gray-600 dark:text-gray-400">
                    {{ $results->count() }} {{ Str::plural('result', $results->count()) }} for "{{ $query }}"
                </p>
            @endif
        </div>

        <!-- Search form - large and centered like index page -->
        <div class="mb-12 max-w-xl mx-auto">
            <x-documentation::search-form :query="$query" />
        </div>

        <!-- Results or empty state -->
        @if($results->isEmpty())
            <div class="max-w-2xl mx-auto">
                <!-- Empty state with minimal design -->
                <div class="text-center space-y-5">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-50 dark:bg-gray-800 mb-2">
                        <x-heroicon-o-magnifying-glass class="h-8 w-8 text-gray-400 dark:text-gray-500" />
                    </div>

                    <h2 class="font-display text-xl font-semibold text-gray-900 dark:text-white">
                        No matches found
                    </h2>

                    <p class="text-gray-600 dark:text-gray-400">
                        We couldn't find anything matching "<span class="font-medium">{{ $query }}</span>"
                    </p>

                    <!-- Suggestions in cards -->
                    <div class="mt-12 grid gap-5">
                        <!-- Tips card -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700 p-6 text-left">
                            <h3 class="font-display text-sm font-medium text-gray-900 dark:text-white uppercase tracking-wider mb-3">Search tips</h3>

                            <ul class="space-y-3 text-sm">
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 h-5 w-5 text-primary-500 mr-2" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <span class="text-gray-600 dark:text-gray-400">Try simpler or alternate terms</span>
                                </li>
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 h-5 w-5 text-primary-500 mr-2" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <span class="text-gray-600 dark:text-gray-400">Check spelling of search terms</span>
                                </li>
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 h-5 w-5 text-primary-500 mr-2" aria-hidden="true">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </span>
                                    <span class="text-gray-600 dark:text-gray-400">Use fewer keywords to broaden results</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- Results grid with minimal card design -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @foreach($results as $result)
                    <a href="{{ $result->url }}" class="group block bg-white dark:bg-gray-800 rounded-lg border border-gray-100 dark:border-gray-700 overflow-hidden transition duration-150 hover:border-primary-200 dark:hover:border-primary-800 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                        <div class="p-6">
                            <!-- Type badge -->
                            <div class="mb-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary-50 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">
                                    {{ $documentTypes[$result->type]['title'] }}
                                </span>
                            </div>

                            <!-- Title and excerpt -->
                            <h2 class="font-display text-lg font-medium text-gray-900 dark:text-white group-hover:text-primary-600 dark:group-hover:text-primary-400 transition">
                                {{ $result->title }}
                            </h2>

                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400 line-clamp-2">
                                {!! $result->excerpt !!}
                            </p>

                            <!-- Read indicator -->
                            <div class="mt-4 flex items-center text-primary-600 dark:text-primary-400">
                                <span class="text-sm font-medium">Read document</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-1 transform group-hover:translate-x-1 transition-transform duration-150" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-documentation::layout>
