<x-cloud::marketing.frame id="faq">
    <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
        <div class="bg-surface px-6 pb-6 pt-20 md:px-10 md:pt-28 lg:col-span-4">
            <div class="lg:sticky lg:top-28">
                <x-cloud::marketing.eyebrow class="mb-5">{{ __('FAQ') }}</x-cloud::marketing.eyebrow>
                <h2 class="type-title text-gray-950 dark:text-white">
                    {{ __('Frequently asked questions') }}
                </h2>
                <p class="mt-4 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                    {{ __('Something else on your mind?') }}
                    <a href="{{ route('contact') }}" class="font-medium text-gray-950 underline decoration-gray-300 underline-offset-4 hover:decoration-gray-950 dark:text-white dark:decoration-gray-600 dark:hover:decoration-white">{{ __('Get in touch') }}</a>
                </p>
            </div>
        </div>

        <div class="bg-surface px-6 pb-16 md:px-10 lg:col-span-8 lg:pt-24">
            <x-cloud::marketing.faq-accordion :faqs="$faqs" />
        </div>
    </div>
</x-cloud::marketing.frame>
