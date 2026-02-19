<!-- Minimalist Footer -->
<footer class="py-12 md:py-16 border-t border-gray-100 dark:border-gray-900 bg-white dark:bg-black">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div
            class="grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-8 pb-10 border-b border-gray-100 dark:border-gray-900">
            <!-- Company Info -->
            <div class="md:col-span-5 space-y-5">
                <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                    <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                </a>
                <p class="text-gray-500 dark:text-gray-400 text-sm leading-relaxed max-w-md">
                    The Next-Generation Open-Source CRM Platform designed to streamline your customer relationships.
                    Built with modern technologies for businesses of all sizes.
                </p>

                <!-- Social links - Simplified -->
                <div class="flex space-x-4">
                    <a href="https://github.com/Relaticle/relaticle" target="_blank" rel="noopener"
                       class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="GitHub">
                        <span class="sr-only">GitHub</span>
                        <x-icon-github class="h-5 w-5" />
                    </a>
                    <a href="https://x.com/relaticle" target="_blank" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="X">
                        <span class="sr-only">X</span>
                        <x-icon-x-twitter class="h-5 w-5" />
                    </a>
                    <a href="https://www.linkedin.com/company/relaticle"  target="_blank" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="LinkedIn">
                        <span class="sr-only">LinkedIn</span>
                        <x-icon-linkedin class="h-5 w-5" />
                    </a>
                </div>
            </div>

            <!-- Quick Links Column - Simplified -->
            <div class="md:col-span-3">
                <h3 class="font-medium text-xs text-black dark:text-white uppercase tracking-wider mb-4">
                    Quick Links
                </h3>
                <ul class="space-y-3">
                    <li>
                        <a href="{{ url('/') }}"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Home
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('documentation.index') }}"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Documentation
                        </a>
                    </li>
                    <li>
                        <a href="{{ url('/#features') }}"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Features
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Legal Links Column - Simplified -->
            <div class="md:col-span-4">
                <h3 class="font-medium text-xs text-black dark:text-white uppercase tracking-wider mb-4">
                    Support & Legal
                </h3>
                <ul class="space-y-3">
                    <li>
                        <a href="{{ url('privacy-policy') }}"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Privacy Policy
                        </a>
                    </li>
                    <li>
                        <a href="{{ url('terms-of-service') }}"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Terms of Service
                        </a>
                    </li>
                    <li>
                        <a href="mailto:manuk.minasyan1@gmail.com"
                           class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                            Contact Us
                        </a>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Copyright section - Simplified -->
        <div class="mt-8 flex flex-col md:flex-row md:justify-between items-center">
            <p class="text-gray-500 dark:text-gray-400 text-xs">&copy; {{ date('Y') }} Relaticle. All rights
                reserved.</p>
            <div class="mt-4 md:mt-0 text-xs text-gray-500 dark:text-gray-400">
                Made with <span class="text-red-500 mx-1">♥</span> for open-source
            </div>
        </div>
    </div>
</footer>
