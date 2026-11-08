@props([
    'label',
    'lead',
    'linkLabel' => null,
    'linkHref' => null,
    'figure' => null,
    'facts' => [],
    'quote' => null,
])

<div {{ $attributes->class('bg-rail grid grid-cols-1 gap-px lg:grid-cols-12') }}>
    <div class="bg-surface flex flex-col justify-between gap-10 p-6 md:p-10 lg:col-span-4">
        <div>
            <x-cloud::marketing.eyebrow class="mb-4">{{ $label }}</x-cloud::marketing.eyebrow>
            <h3 class="type-lead">
                <span class="text-gray-950 dark:text-white">{{ $lead }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $slot }}</span>
            </h3>

            @if($facts !== [])
                <ul class="divide-rail border-rail mt-6 divide-y border-y type-prose text-gray-700 dark:text-gray-300">
                    @foreach($facts as $fact)
                        <li class="py-2.5">{{ $fact }}</li>
                    @endforeach
                </ul>
            @endif

            @if($quote)
                <x-cloud::marketing.quote
                    class="border-rail mt-6 border-t pt-5"
                    :quote="$quote['quote']"
                    :name="$quote['name']"
                    :role="$quote['role']"
                    :company="$quote['company']"
                    :url="$quote['url'] ?? null"
                    :avatar="$quote['avatar'] ?? null"
                />
            @endif
        </div>

        @if($linkHref)
            <a href="{{ $linkHref }}" class="group inline-flex w-fit items-center gap-1 text-sm font-medium text-gray-950 transition-colors hover:text-primary dark:text-white dark:hover:text-primary-400">
                {{ $linkLabel }}
                <x-lucide-arrow-right class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"/>
            </a>
        @endif
    </div>

    <div class="bg-tint feat-card relative min-h-[22rem] overflow-hidden md:min-h-[26rem] lg:col-span-8">
        @if($figure)
            <x-cloud::marketing.eyebrow class="pointer-events-none absolute left-6 top-5 z-10">{{ $figure }}</x-cloud::marketing.eyebrow>
        @endif

        {{ $visual }}
    </div>
</div>
