@props([
    'align' => 'start',
])

<section {{ $attributes->class('bg-surface border-rail border-b') }}>
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        @if($align === 'center')
            <div class="flex flex-col items-center px-6 pb-10 pt-9 text-center md:px-10 md:pb-12 md:pt-12">
                @isset($eyebrow)
                    <div class="mb-6">{{ $eyebrow }}</div>
                @endisset

                <h1 class="type-display max-w-3xl text-gray-950 dark:text-white">{{ $title }}</h1>

                @isset($aside)
                    <div class="mt-6 flex w-full max-w-2xl flex-col items-center">{{ $aside }}</div>
                @endisset
            </div>
        @else
            <div class="grid gap-8 px-6 pb-12 pt-9 md:px-10 md:pb-14 md:pt-9 lg:grid-cols-12 lg:items-end lg:gap-10">
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
        @endif

        {{ $slot }}
    </div>
</section>
