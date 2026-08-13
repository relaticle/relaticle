<section id="faq" class="py-24 md:py-32 bg-gray-50 dark:bg-gray-950 relative overflow-hidden">
    <div class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(0_0_0/0.04)_1px,transparent_0)] dark:bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.035)_1px,transparent_0)] bg-[size:24px_24px] [mask-image:radial-gradient(ellipse_60%_50%_at_50%_50%,black_25%,transparent_100%)]"></div>

    <!-- Bottom gradient fade into next section -->
    <div class="absolute bottom-0 left-0 right-0 h-32 bg-gradient-to-b from-transparent to-white dark:to-gray-950 pointer-events-none"></div>

    <div class="relative max-w-3xl mx-auto px-6 lg:px-8">
        <div class="max-w-2xl mx-auto text-center mb-16">
            <h2 class="font-display text-3xl sm:text-4xl md:text-[2.75rem] font-bold text-gray-950 dark:text-white tracking-[-0.02em] leading-[1.15]">
                Frequently Asked Questions
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-500 dark:text-gray-400 max-w-lg mx-auto leading-relaxed">
                Everything you need to know about Relaticle, from deployment to AI agent integration.
            </p>
        </div>

        <x-marketing.faq-accordion :faqs="$faqs" />
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var e = [0.22, 1, 0.36, 1];
            inView('#faq .divide-y', function() {
                animate('.faq-item', { y: [20, 0] }, { delay: stagger(0.08), duration: 0.5, ease: e });
            }, { amount: 0.15 });
        });
    </script>
</section>
