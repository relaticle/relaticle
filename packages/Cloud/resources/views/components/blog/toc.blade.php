@props(['post'])

@php
    $toc = $post->tableOfContents();
@endphp

@if(count($toc))
    <div class="sticky top-28 max-h-[calc(100vh-8rem)] overflow-y-auto">
        <x-cloud::marketing.eyebrow class="mb-4">{{ __('On this page') }}</x-cloud::marketing.eyebrow>
        <nav aria-label="{{ __('On this page') }}">
            <ul class="space-y-2.5">
                @foreach($toc as $fragment => $heading)
                    <li class="text-sm">
                        <a href="#{{ $fragment }}"
                           class="border-rail flex items-center border-l py-1 pl-3 text-gray-700 transition-colors hover:border-primary-500 hover:text-gray-950 dark:text-gray-300 dark:hover:border-primary-400 dark:hover:text-white">
                            <span class="truncate">{{ $heading }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
@endif
