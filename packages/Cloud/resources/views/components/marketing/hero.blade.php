@props([
    'align' => 'center',
])

<section {{ $attributes->class('bg-surface border-rail border-b') }}>
    <div class="border-rail mx-auto max-w-6xl sm:border-x">
        @if($align === 'start')
            <div class="px-6 pb-10 pt-9 md:px-10 md:pb-12 md:pt-12">
                @isset($eyebrow)
                    <div class="mb-6">{{ $eyebrow }}</div>
                @endisset

                <h1 class="type-display max-w-4xl text-gray-950 dark:text-white">{{ $title }}</h1>

                @isset($aside)
                    <div class="mt-5 flex max-w-2xl flex-col items-start">{{ $aside }}</div>
                @endisset
            </div>
        @else
            <div class="flex flex-col items-center px-6 pb-10 pt-9 text-center md:px-10 md:pb-12 md:pt-12">
                @isset($eyebrow)
                    <div class="mb-6">{{ $eyebrow }}</div>
                @endisset

                <h1 class="type-display max-w-3xl text-gray-950 dark:text-white">{{ $title }}</h1>

                @isset($aside)
                    <div class="mt-5 flex w-full max-w-2xl flex-col items-center">{{ $aside }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </div>
</section>
