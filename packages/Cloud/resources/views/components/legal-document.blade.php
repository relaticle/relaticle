@props([
    'title',
    'subtitle',
    'content',
])

<div class="py-16 md:py-24 bg-white dark:bg-gray-950 relative">
    <!-- Subtle background elements -->
    <div class="absolute inset-0 bg-grid-pattern opacity-[0.01] dark:opacity-[0.02]"></div>
    <div class="absolute top-24 left-24 w-36 h-36 md:w-96 md:h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-24 right-24 w-36 h-36 md:w-96 md:h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 relative">
        <!-- Header Section -->
        <div class=" space-y-6 max-w-3xl mb-12">
            <h1 class="text-4xl sm:text-5xl font-bold text-black dark:text-white leading-[1.1] tracking-tight">
                <span class="relative inline-block">
                    <span class="relative z-10">{{ $title }}</span>
                    <span class="absolute bottom-2 sm:left-0 right-1/4 w-1/2 sm:w-full h-3 bg-primary/10 dark:bg-primary/20 -rotate-1 z-0"></span>
                </span>
            </h1>
            <p class="text-lg text-gray-600 dark:text-gray-300 max-w-2xl leading-relaxed">
                {{ $subtitle }}
            </p>
        </div>

        <!-- Content Section -->
        <div class="prose dark:prose-invert prose-headings:font-medium prose-headings:text-black dark:prose-headings:text-white prose-a:text-primary dark:prose-a:text-primary-400 prose-img:rounded-xl prose-img:shadow-md max-w-none">
            {!! $content !!}
        </div>
    </div>
</div>
