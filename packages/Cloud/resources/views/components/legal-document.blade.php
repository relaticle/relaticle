@props([
    'title',
    'subtitle',
    'content',
])

<x-cloud::marketing.hero align="start">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-slot:aside>
        <p class="text-base leading-relaxed text-gray-500 md:text-lg dark:text-gray-400">{{ $subtitle }}</p>
    </x-slot:aside>
</x-cloud::marketing.hero>

<x-cloud::marketing.frame>
    <div class="px-6 py-12 md:px-10 md:py-16">
        <div class="prose max-w-3xl dark:prose-invert prose-headings:font-medium prose-headings:text-gray-950 dark:prose-headings:text-white prose-a:text-primary dark:prose-a:text-primary-400 prose-img:rounded-xl">
            {!! $content !!}
        </div>
    </div>
</x-cloud::marketing.frame>
