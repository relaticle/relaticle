@props(['nav' => [], 'currentPath' => null])

@php
    /** Areas keep their order of first appearance, so help stays above developers. */
    $areas = collect($nav)->groupBy('area');
@endphp

{{-- Deliberately not a <nav> itself: each call site wraps it in one, because
     the markdown response strips <nav> with a non-nesting regex. --}}
<div class="space-y-8 text-[13px]">
    @foreach($areas as $sections)
        <div>
            <p class="px-1 text-pico font-semibold tracking-[0.08em] text-gray-400 uppercase dark:text-gray-500">
                {{ $sections->first()['areaTitle'] }}
            </p>

            <div class="mt-3 space-y-5">
                @foreach($sections as $section)
                    <div>
                        <a href="{{ $section['url'] }}"
                           @class([
                               'flex items-center gap-2 rounded-md px-1 py-1 font-display text-[13px] font-semibold tracking-tight transition-colors',
                               'text-primary-600 dark:text-primary-400' => $currentPath === $section['path'],
                               'text-gray-900 hover:text-primary-600 dark:text-white dark:hover:text-primary-400' => $currentPath !== $section['path'],
                           ])
                           @if($currentPath === $section['path']) aria-current="page" @endif>
                            <x-documentation::doc-icon :topic="$section['path']" class="h-4 w-4 shrink-0" />
                            {{ $section['title'] }}
                        </a>

                        <ul class="mt-1.5 space-y-px border-l border-gray-200 dark:border-white/10">
                            @foreach($section['links'] as $link)
                                <li>
                                    <a href="{{ $link['url'] }}"
                                       @class([
                                           '-ml-px block border-l py-1.5 pl-4 transition-colors',
                                           'border-primary-500 font-medium text-primary-600 dark:border-primary-400 dark:text-primary-400' => $currentPath !== null && $currentPath === $link['path'],
                                           'border-transparent text-gray-600 hover:border-gray-300 hover:text-gray-900 dark:text-gray-400 dark:hover:border-white/25 dark:hover:text-white' => $currentPath === null || $currentPath !== $link['path'],
                                       ])
                                       @if($currentPath !== null && $currentPath === $link['path']) aria-current="page" @endif>
                                        {{ $link['title'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
