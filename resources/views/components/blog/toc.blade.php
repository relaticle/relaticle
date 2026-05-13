@props(['post'])

@php
    preg_match_all('/<h2[^>]*><a[^>]*id="([^"]+)"[^>]*>#<\/a>([^<]+)/', $post->toHtml(), $matches);
    $toc = (! empty($matches[1]) && ! empty($matches[2]))
        ? array_combine($matches[1], $matches[2])
        : [];
@endphp

@if(count($toc))
    <div class="sticky top-24 pt-0.5 overflow-y-auto pb-16">
        <h3 class="text-sm font-semibold text-black dark:text-white mb-4 flex items-center space-x-2">
            <x-heroicon-o-list-bullet class="h-4 w-4 text-primary dark:text-primary-400" />
            <span>On this page</span>
        </h3>
        <nav>
            <ul class="space-y-2.5">
                @foreach($toc as $fragment => $heading)
                    <li class="text-sm">
                        <a href="#{{ $fragment }}"
                           class="group flex items-center text-gray-600 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 transition-colors border-l border-gray-200 dark:border-gray-800 pl-3 py-1 hover:border-primary-500 dark:hover:border-primary-400">
                            <span class="truncate">{{ $heading }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>
@endif
