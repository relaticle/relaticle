<section id="faq" class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(0_0_0/0.04)_1px,transparent_0)] dark:bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.035)_1px,transparent_0)] bg-[size:24px_24px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,black_25%,transparent_100%)]"></div>

    <!-- Bottom gradient fade into next section -->
    <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-b from-transparent to-white dark:to-black pointer-events-none"></div>

    <div class="relative max-w-3xl mx-auto px-6 lg:px-8">
        <div class="max-w-2xl mx-auto text-center mb-16">
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                Frequently Asked Questions
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 max-w-lg mx-auto leading-relaxed">
                Everything you need to know about Relaticle, from deployment to AI agent integration.
            </p>
        </div>

        <div x-data="{ open: null }" class="divide-y divide-gray-200/80 dark:divide-white/[0.06]">
            @foreach($faqs as $index => [$question, $answer])
                <div class="faq-item py-5">
                    <button @click="open = open === {{ $index }} ? null : {{ $index }}"
                            class="flex w-full items-center justify-between text-left gap-4 cursor-pointer hover:text-primary dark:hover:text-primary-400 transition-colors duration-150">
                        <span class="text-base font-semibold text-gray-900 dark:text-white">
                            {{ $question }}
                        </span>
                        <x-ri-arrow-down-s-line class="h-5 w-5 shrink-0 text-gray-400 transition-transform duration-200" ::class="open === {{ $index }} ? 'rotate-180' : ''"/>
                    </button>
                    <div x-show="open === {{ $index }}" x-collapse class="mt-3 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                        {{ $answer }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var e = [0.22, 1, 0.36, 1];
            document.querySelectorAll('.faq-item').forEach(function(item) { item.style.opacity = '0'; });
            inView('#faq .divide-y', function() {
                animate('.faq-item', { opacity: [0, 1], y: [20, 0] }, { delay: stagger(0.08), duration: 0.5, ease: e });
            }, { amount: 0.15 });
        });
    </script>
</section>
