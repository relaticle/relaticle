@php
    $baseTitle = __('Documentation');
    $pageDescription = __('Guides and resources to help you get the most out of Relaticle CRM.');

    $sections = collect($nav)->where('area', \Relaticle\Documentation\Support\DocUrl::DOCS);
@endphp

<x-documentation::shell
    :title="$baseTitle . ' - ' . config('app.name')"
    :description="$pageDescription"
    :nav="$nav">
    <div class="mx-auto max-w-3xl">
        <p class="text-pico font-semibold tracking-[0.08em] text-primary-600 uppercase dark:text-primary-400">
            {{ __('Developers') }}
        </p>
        <h1 class="font-display mt-3 text-4xl font-bold tracking-[-0.02em] text-gray-950 sm:text-[2.75rem] dark:text-white">
            {{ $baseTitle }}
        </h1>
        <p class="mt-4 max-w-2xl text-lg leading-relaxed text-gray-500 dark:text-gray-400">
            {{ $pageDescription }}
        </p>
    </div>

    <div class="mx-auto mt-14 max-w-5xl space-y-14">
        @foreach($sections as $section)
            <section>
                <h2 class="sr-only">{{ $section['title'] }}</h2>
                <ul class="grid gap-px overflow-hidden rounded-xl border border-gray-200/80 bg-gray-200/80 sm:grid-cols-2 dark:border-white/[0.06] dark:bg-white/[0.06]">
                    @foreach($section['links'] as $link)
                        <li class="bg-white dark:bg-gray-950">
                            <a href="{{ $link['url'] }}" class="group flex h-full flex-col p-6 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                <span class="text-gray-400 transition-colors group-hover:text-primary-600 dark:text-gray-500 dark:group-hover:text-primary-400">
                                    <x-documentation::doc-icon :topic="$link['path'] ?? 'api-reference'" class="h-5 w-5" />
                                </span>
                                <span class="font-display mt-4 block text-[15px] font-semibold tracking-tight text-gray-900 dark:text-white">
                                    {{ $link['title'] }}
                                </span>
                                <span class="mt-1.5 block text-[13px] leading-relaxed text-gray-500 dark:text-gray-400">
                                    {{ $link['description'] }}
                                </span>
                                <span class="mt-5 inline-flex items-center gap-1 text-xs font-medium text-gray-900 transition-colors group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">
                                    {{ __('Read guide') }}
                                    <x-ri-arrow-right-line class="h-3 w-3 transition-transform group-hover:translate-x-0.5" />
                                </span>
                            </a>
                        </li>
                    @endforeach
                    @if(count($section['links']) % 2 === 1)
                        <li class="hidden bg-white sm:block dark:bg-gray-950" aria-hidden="true"></li>
                    @endif
                </ul>
            </section>
        @endforeach

        <section class="rounded-xl border border-gray-200/80 bg-[var(--surface-card-bg)] p-6 sm:flex sm:items-center sm:justify-between sm:gap-6 dark:border-white/[0.06]">
            <div>
                <h2 class="font-display text-base font-semibold tracking-tight text-gray-950 dark:text-white">{{ __('Using Relaticle, not building on it?') }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('The help centre covers the day-to-day: records, pipelines, imports, and team access.') }}
                </p>
            </div>
            <div class="mt-4 shrink-0 sm:mt-0">
                <x-marketing.button variant="secondary" size="sm" href="{{ route('help.index') }}" icon-trailing="ri-arrow-right-line">
                    {{ __('Visit the help centre') }}
                </x-marketing.button>
            </div>
        </section>
    </div>

    @php
        $jsonLd = (new \Relaticle\Documentation\Support\DocsJsonLd)->breadcrumbs([
            ['name' => $baseTitle, 'url' => route('documentation.index')],
        ]);
    @endphp

    {!! $jsonLd->toScript() !!}
</x-documentation::shell>
