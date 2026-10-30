@if ($paginator->hasPages())
    @php
        $baseClasses = 'inline-flex items-center justify-center h-9 min-w-9 px-3 rounded-lg text-sm font-medium transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-black';
        $linkClasses = $baseClasses.' text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-white/[0.06]';
        $currentClasses = $baseClasses.' bg-primary text-white';
        $disabledClasses = $baseClasses.' text-gray-300 dark:text-gray-700 cursor-not-allowed';
        $separatorClasses = $baseClasses.' text-gray-400 dark:text-gray-600';
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex flex-col-reverse items-center gap-5 sm:flex-row sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {!! __('Showing :first–:last of :total posts', [
                'first' => '<span class="font-medium text-gray-700 dark:text-gray-200">'.$paginator->firstItem().'</span>',
                'last' => '<span class="font-medium text-gray-700 dark:text-gray-200">'.$paginator->lastItem().'</span>',
                'total' => '<span class="font-medium text-gray-700 dark:text-gray-200">'.$paginator->total().'</span>',
            ]) !!}
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $disabledClasses }}" aria-disabled="true" aria-label="{{ __('Previous page') }}">
                    <x-ri-arrow-left-s-line class="w-4 h-4" aria-hidden="true" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $linkClasses }}"
                   aria-label="{{ __('Previous page') }}">
                    <x-ri-arrow-left-s-line class="w-4 h-4" aria-hidden="true" />
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $separatorClasses }}" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $currentClasses }}" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="{{ $linkClasses }}"
                               aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $linkClasses }}"
                   aria-label="{{ __('Next page') }}">
                    <x-ri-arrow-right-s-line class="w-4 h-4" aria-hidden="true" />
                </a>
            @else
                <span class="{{ $disabledClasses }}" aria-disabled="true" aria-label="{{ __('Next page') }}">
                    <x-ri-arrow-right-s-line class="w-4 h-4" aria-hidden="true" />
                </span>
            @endif
        </div>
    </nav>
@endif
