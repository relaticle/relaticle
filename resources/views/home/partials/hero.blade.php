<!-- Modern Minimalist Hero Section -->
<section class="relative py-16 md:py-24 bg-white dark:bg-black overflow-hidden">
    <!-- Subtle background elements -->
    <div class="absolute inset-0 bg-grid-pattern opacity-[0.01] dark:opacity-[0.02]"></div>
    <div class="absolute -top-24 -left-24 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>
    <div class="absolute -bottom-24 -right-24 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 relative">
        <div class="space-y-14 md:space-y-16">
            <!-- Tech Badge - Simplified -->
            <div class="flex justify-center">
                <div
                    class="inline-flex items-center px-3 py-1.5 border border-gray-100 dark:border-gray-800 rounded-full text-xs shadow-sm">
                    <span class="text-gray-500 dark:text-gray-400 mr-2">Built with</span>
                    <div class="flex items-center gap-1">
                        <div class="flex items-center gap-1">
                            <x-icon-laravel class="h-3.5 w-3.5 "/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">Laravel</span>
                        </div>
                        <span class="text-gray-400">·</span>
                        <div class="flex items-center gap-1">
                            <x-icon-filament class="h-3.5 w-3.5 dark:fill-white"/>
                            <span class="font-medium text-gray-700 dark:text-gray-300">Filament</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hero Text - Enhanced Typography -->
            <div class="text-center space-y-6 max-w-3xl mx-auto">
                <h1 class="text-4xl sm:text-5xl md:text-6xl font-bold text-black dark:text-white leading-[1.1] tracking-tight">
                    The Next-Generation<br class="hidden sm:block"/> <span class="relative inline-block">
                        <span class="relative z-10">Open-Source CRM</span>
                        <span
                            class="absolute bottom-2 sm:left-0 right-1/4 w-1/2 sm:w-full h-3 bg-primary/10 dark:bg-primary/20 sm:dark:bg-primary/30 -rotate-1 z-0"></span>
                    </span>
                </h1>

                <p class="text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
                    Transforming client relationship management with a modern, intuitive approach. Built for businesses
                    that value simplicity and efficiency.
                </p>
            </div>

            <!-- CTA Section - Refined -->
            <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
                <a href="{{ route('register') }}"
                   class="group w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-primary hover:bg-primary-600 text-white px-7 py-3.5 rounded-md text-base font-medium transition-all duration-200 shadow-sm hover:shadow">
                    <span>Start for free</span>
                    <x-heroicon-c-arrow-right class="h-3.5 w-3.5 transition-transform duration-300 group-hover:translate-x-1"/>
                </a>

                <a href="https://github.com/relaticle/relaticle" target="_blank"
                   class="group w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-md text-gray-600 dark:text-gray-300 hover:text-black dark:hover:text-white border border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700 transition-all duration-200 bg-white/50 dark:bg-black/50">
                    <x-icon-github class="h-5 w-5 transition-transform duration-300 group-hover:scale-110"/>
                    <span class="font-medium">GitHub</span>
                </a>
            </div>

            <!-- App Preview - Cleaner Mockup -->
            <div class="mt-16 md:mt-20 max-w-5xl mx-auto">
                <div
                    class="bg-white dark:bg-gray-900 rounded-lg overflow-hidden border border-gray-100 dark:border-gray-800 shadow-lg dark:shadow-gray-950/20 hover:shadow-xl transition-shadow duration-300">
                    <!-- Browser Header -->
                    <div
                        class="bg-gray-50 dark:bg-gray-800/80 border-b border-gray-100 dark:border-gray-700 px-4 py-2 flex items-center">
                        <!-- Window Controls -->
                        <div class="flex space-x-1.5">
                            <div class="w-3 h-3 rounded-full bg-gray-300/80 dark:bg-gray-600/80"></div>
                            <div class="w-3 h-3 rounded-full bg-gray-300/80 dark:bg-gray-600/80"></div>
                            <div class="w-3 h-3 rounded-full bg-gray-300/80 dark:bg-gray-600/80"></div>
                        </div>

                        <!-- Browser Address Bar -->
                        <div
                            class="ml-4 flex-1 bg-white/90 dark:bg-gray-700/80 rounded-md px-3 py-1 text-xs text-gray-600 dark:text-gray-300 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-green-500 mr-1.5" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <span>app.relaticle.com</span>
                        </div>
                    </div>

                    <!-- Browser Content Area -->
                    <div class="relative">
                        <img id="app-companies-preview-image"
                             src="{{ asset('images/app-companies-preview.jpg') }}"
                             alt="Relaticle CRM Dashboard"
                             class="w-full h-auto"
                             width="1200"
                             height="675"
                             loading="lazy">

                        <!-- Subtle highlight overlay -->
                        <div
                            class="absolute inset-0 bg-gradient-to-tr from-primary/5 via-transparent to-transparent opacity-60 pointer-events-none"></div>
                    </div>
                </div>
            </div>

            <!-- Key Highlights - Simplified -->
            <div
                class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-14 border-t border-gray-100 dark:border-gray-800 pt-10 px-2">
                <div class="p-3 text-center">
                    <div class="text-lg font-semibold text-black dark:text-white">Open Source</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">Free to use and customize</div>
                </div>
                <div class="p-3 text-center">
                    <div class="text-lg font-semibold text-black dark:text-white">Modern Stack</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">PHP 8.4, Laravel 12</div>
                </div>
                <div class="p-3 text-center">
                    <div class="text-lg font-semibold text-black dark:text-white">Secure</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enterprise-grade security</div>
                </div>
                <div class="p-3 text-center">
                    <div class="text-lg font-semibold text-black dark:text-white">Scalable</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">Grows with your business</div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const appPreviewImage = document.getElementById('app-companies-preview-image');
        const lightImage = "{{ asset('images/app-companies-preview.jpg') }}";
        const darkImage = "{{ asset('images/app-companies-preview-dark.jpg') }}";

        // Initial setup based on current theme
        updateImageSource();

        // Create a MutationObserver to detect changes to the html element's class list
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.attributeName === 'class') {
                    updateImageSource();
                }
            });
        });

        // Start observing the html element for class changes
        observer.observe(document.documentElement, {attributes: true});

        // Function to update the image source based on dark mode
        function updateImageSource() {
            if (document.documentElement.classList.contains('dark')) {
                appPreviewImage.src = darkImage;
            } else {
                appPreviewImage.src = lightImage;
            }
        }
    });
</script>
