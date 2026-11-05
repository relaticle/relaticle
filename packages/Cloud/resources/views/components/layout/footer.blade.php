@props([
    'framed' => false,
])

@php
    $columns = app(\Relaticle\Cloud\Marketing\Support\MarketingNavigation::class)->footer();

    $socialLinks = [
        ['ri-github-fill', 'GitHub', 'https://github.com/relaticle/relaticle'],
        ['ri-discord-fill', 'Discord', route('discord')],
        ['ri-twitter-x-fill', 'X', 'https://x.com/relaticle'],
        ['ri-linkedin-box-fill', 'LinkedIn', 'https://www.linkedin.com/company/relaticle'],
    ];
@endphp

@php($footerVariant = request()->query('footer', 'b'))

@if($framed && in_array($footerVariant, ['b', 'c', 'd'], true))
    @include('cloud::components.layout.footer-quiet', [
        'dark' => $footerVariant === 'c',
        'wordmark' => $footerVariant === 'd',
    ])
@elseif($framed)
    <footer class="bg-surface">
        <div class="border-rail mx-auto max-w-6xl sm:border-x">
            <div class="bg-rail grid grid-cols-2 gap-px md:grid-cols-4">
                <div class="bg-surface col-span-2 p-6 md:row-span-2 md:p-10">
                    <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                        <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                    </a>
                    <p class="type-prose mt-5 max-w-md text-gray-500 dark:text-gray-400">
                        {{ __('The open-source AI CRM for selling without a sales team. Run it yourself or on Relaticle Cloud, with no per-seat pricing.') }}
                    </p>
                </div>

                @foreach($socialLinks as [$icon, $name, $url])
                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                       class="bg-surface hover:bg-tint flex items-center justify-center gap-2.5 px-4 py-5 text-sm font-semibold tracking-tight text-gray-800 transition-colors dark:text-gray-200">
                        <x-dynamic-component :component="$icon" class="h-5 w-5 shrink-0 text-gray-900 dark:text-gray-200" />
                        {{ $name }}
                    </a>
                @endforeach
            </div>

            <nav aria-label="{{ __('Footer') }}" class="bg-rail border-rail grid grid-cols-2 gap-px border-t md:grid-cols-4">
                @foreach($columns as $column)
                    @php($columnId = 'footer-'.\Illuminate\Support\Str::slug($column->label))
                    <div class="bg-surface p-6 md:p-10">
                        <x-cloud::marketing.eyebrow :id="$columnId" class="mb-5">{{ $column->label }}</x-cloud::marketing.eyebrow>
                        <ul aria-labelledby="{{ $columnId }}" class="space-y-3">
                            @foreach($column->children as $item)
                                <li>
                                    <a href="{{ $item->url }}"
                                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                                       @if(url()->current() === $item->url) aria-current="page" @endif
                                       class="text-sm text-gray-600 transition-colors hover:text-gray-950 aria-[current=page]:text-gray-950 dark:text-gray-400 dark:hover:text-white dark:aria-[current=page]:text-white">
                                        {{ $item->label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>

            <div class="border-rail flex flex-col items-center gap-4 border-t px-6 py-5 md:flex-row md:justify-between md:px-10">
                <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400">
                    <p>{{ __('© :year Relaticle. All rights reserved.', ['year' => date('Y')]) }}</p>

                    {{-- The Documentation provider registers this route, so an unguarded route() here 500s every
                         marketing page when that feature is off. --}}
                    @feature(App\Features\Documentation::class)
                        <a href="{{ route('llms-txt') }}" class="transition-colors hover:text-gray-950 dark:hover:text-white">llms.txt</a>
                    @endfeature
                </div>

                <x-theme-switcher />
            </div>
        </div>
    </footer>
@else
    <footer class="py-12 md:py-16 border-t border-gray-100 dark:border-gray-900 bg-white dark:bg-gray-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-8 pb-10 border-b border-gray-100 dark:border-gray-900">
                <div class="md:col-span-4 space-y-5">
                    <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                        <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                    </a>
                    <p class="text-gray-500 dark:text-gray-400 text-sm leading-relaxed max-w-md">
                        The open-source AI CRM for selling without a sales team. Run it yourself or on Relaticle Cloud, with no per-seat pricing.
                    </p>

                    <div class="flex space-x-4">
                        <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener noreferrer"
                           class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                           aria-label="GitHub">
                            <x-ri-github-fill class="h-5 w-5" />
                        </a>
                        <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer"
                           class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                           aria-label="Discord">
                            <x-ri-discord-fill class="h-5 w-5" />
                        </a>
                        <a href="https://x.com/relaticle" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                           aria-label="X">
                            <x-ri-twitter-x-fill class="h-5 w-5" />
                        </a>
                        <a href="https://www.linkedin.com/company/relaticle" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                           aria-label="LinkedIn">
                            <x-ri-linkedin-box-fill class="h-5 w-5" />
                        </a>
                    </div>
                </div>

                    <nav aria-label="{{ __('Footer') }}" class="md:col-span-8 grid grid-cols-2 md:grid-cols-4 gap-8">
                    {{-- The column labels are list labels, not document sections: as
                         headings they appended four <h3>s to every page's outline,
                         nested under whichever <h2> the page happened to end on.
                         `aria-labelledby` keeps each list named without that. --}}
                    @foreach($columns as $column)
                        @php($columnId = 'footer-'.\Illuminate\Support\Str::slug($column->label))
                        <div>
                            <p id="{{ $columnId }}" class="mb-4 font-medium text-xs text-black dark:text-white uppercase tracking-wider">
                                {{ $column->label }}
                            </p>
                            <ul aria-labelledby="{{ $columnId }}" class="space-y-3">
                                @foreach($column->children as $item)
                                    <li>
                                        <a href="{{ $item->url }}"
                                           @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                                           @if(url()->current() === $item->url) aria-current="page" @endif
                                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                                            {{ $item->label }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </nav>
            </div>

            <div class="flex flex-col md:flex-row md:justify-between items-center gap-4 mt-8">
                <div class="flex items-center gap-4">
                    <p class="text-gray-500 dark:text-gray-400 text-xs">&copy; {{ date('Y') }} Relaticle. All rights
                        reserved.</p>

                    {{-- The Documentation provider registers this route, so it is absent
                         when that feature is off. An unguarded route() here 500s every
                         marketing page. --}}
                    @feature(App\Features\Documentation::class)
                        <a href="{{ route('llms-txt') }}"
                           class="text-gray-500 dark:text-gray-400 text-xs hover:text-primary dark:hover:text-primary-400 transition-colors">llms.txt</a>
                    @endfeature
                </div>

                <x-theme-switcher />
            </div>
        </div>
    </footer>
@endif
