<section {{ $attributes->class('bg-surface border-rail border-b pt-16') }}>
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        <div class="grid gap-8 px-6 pb-12 pt-14 md:px-10 md:pb-14 md:pt-20 lg:grid-cols-12 lg:items-end lg:gap-10">
            <div class="lg:col-span-7">
                @isset($eyebrow)
                    <div class="mb-6">{{ $eyebrow }}</div>
                @endisset

                <h1 class="type-display text-gray-950 dark:text-white">{{ $title }}</h1>
            </div>

            <div class="lg:col-span-5 lg:pb-2">
                {{ $aside }}
            </div>
        </div>

        {{ $slot }}
    </div>
</section>
