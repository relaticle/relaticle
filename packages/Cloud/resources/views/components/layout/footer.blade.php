@php
    $navigation = app(\Relaticle\Cloud\Marketing\Support\MarketingNavigation::class);
    $columns = $navigation->footer();
    $legalLinks = $navigation->legal();

    $socialLinks = [
        ['ri-github-fill', 'GitHub', 'https://github.com/relaticle/relaticle'],
        ['ri-discord-fill', 'Discord', route('discord')],
        ['ri-twitter-x-fill', 'X', 'https://x.com/relaticle'],
        ['ri-linkedin-box-fill', 'LinkedIn', 'https://www.linkedin.com/company/relaticle'],
    ];
@endphp

<footer class="bg-surface">
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        <div class="grid gap-12 px-6 py-14 md:grid-cols-12 md:px-10 md:py-20">
            <div class="md:col-span-4">
                <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                    <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                </a>
                <p class="mt-5 max-w-xs text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    {{ __('The open-source AI CRM for selling without a sales team. Run it yourself or on Relaticle Cloud, with no per-seat pricing.') }}
                </p>

                <div class="-ms-2 mt-8 flex gap-1">
                    @foreach($socialLinks as [$icon, $name, $url])
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $name }}"
                           class="hover:bg-tint flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:text-gray-950 dark:text-gray-400 dark:hover:text-white">
                            <x-dynamic-component :component="$icon" class="h-5 w-5" />
                        </a>
                    @endforeach
                </div>
            </div>

            <nav aria-label="{{ __('Footer') }}" class="grid grid-cols-2 gap-x-6 gap-y-10 sm:grid-cols-4 md:col-span-8">
                @foreach($columns as $column)
                    @php($columnId = 'footer-'.\Illuminate\Support\Str::slug($column->label))
                    <div>
                        <p id="{{ $columnId }}" class="text-sm font-medium text-gray-950 dark:text-white">{{ $column->label }}</p>
                        <ul aria-labelledby="{{ $columnId }}" class="mt-4 space-y-3">
                            @foreach($column->children as $item)
                                <li>
                                    <a href="{{ $item->url }}"
                                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                                       @if(url()->current() === $item->url) aria-current="page" @endif
                                       class="text-sm text-gray-500 transition-colors hover:text-gray-950 aria-[current=page]:text-gray-950 dark:text-gray-400 dark:hover:text-white dark:aria-[current=page]:text-white">
                                        {{ $item->label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </div>

        <div class="border-rail flex flex-col items-center gap-4 border-t px-6 py-5 md:flex-row md:justify-between md:px-10">
            <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm text-gray-500 md:justify-start dark:text-gray-400">
                <p>{{ __('© :year Relaticle, Inc. All rights reserved.', ['year' => now()->year]) }}</p>

                @foreach($legalLinks as $item)
                    <a href="{{ $item->url }}"
                       @if(url()->current() === $item->url) aria-current="page" @endif
                       class="transition-colors hover:text-gray-950 aria-[current=page]:text-gray-950 dark:hover:text-white dark:aria-[current=page]:text-white">{{ $item->label }}</a>
                @endforeach

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

