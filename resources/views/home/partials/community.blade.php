<!-- Modern Minimalist Community Section -->
<section class="py-24 md:py-32 bg-white dark:bg-black relative overflow-hidden">
    <!-- Subtle gradient accent -->
    <div class="absolute top-0 right-0 w-96 h-96 bg-primary/5 dark:bg-primary/10 rounded-full blur-3xl"></div>

    <div class="container max-w-6xl mx-auto px-6 lg:px-8 relative">
        <!-- Section heading -->
        <div class="max-w-3xl mx-auto text-center mb-16 md:mb-20">
            <span
                class="inline-block px-3 py-1 bg-gray-50 dark:bg-gray-900 rounded-full text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                Community
            </span>
            <h2 class="font-display mt-4 text-3xl sm:text-4xl font-bold text-black dark:text-white">
                Collaborate and Grow Together
            </h2>
            <p class="mt-5 text-base md:text-lg text-gray-600 dark:text-gray-300 max-w-2xl mx-auto leading-relaxed">
                As an open-source platform, Relaticle thrives on community collaboration. Join our growing community to
                get help, share ideas, and contribute.
            </p>
        </div>

        <div class="max-w-4xl mx-auto">
            <!-- Community channels - Consolidated grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-16">
                <!-- GitHub -->
                <div class="bg-gray-50 dark:bg-gray-900 p-8 rounded-xl">
                    <div class="flex flex-col h-full">
                        <div
                            class="bg-primary/10 dark:bg-primary/20 p-3 rounded-lg inline-flex w-12 h-12 items-center justify-center mb-5">
                            <x-icon-github class="w-6 h-6 text-primary dark:text-primary-400"/>
                        </div>
                        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-3">GitHub</h3>
                        <p class="text-gray-600 dark:text-gray-300 mb-6 text-sm">Star our repo, report issues, and
                            contribute code. Relaticle is completely open source and free to use, modify and
                            distribute.</p>
                        <div class="mt-auto">
                            <a href="https://github.com/relaticle/relaticle" target="_blank"
                               class="inline-flex items-center text-primary dark:text-primary-400 hover:text-primary-600 dark:hover:text-primary-300 font-medium text-sm transition-colors">
                                View Repository
                                <svg xmlns="http://www.w3.org/2000/svg" class="ml-1.5 h-4 w-4" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                          d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Discord -->
                <div class="bg-gray-50 dark:bg-gray-900 p-8 rounded-xl">
                    <div class="flex flex-col h-full">
                        <div
                            class="bg-primary/10 dark:bg-primary/20 p-3 rounded-lg inline-flex w-12 h-12 items-center justify-center mb-5">
                          <x-icon-discord class="w-6 h-6 text-primary dark:text-primary-400" />
                        </div>
                        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-3">Discord Community</h3>
                        <p class="text-gray-600 dark:text-gray-300 mb-6 text-sm">Chat with developers, get help, and
                            share ideas. Join our growing community and connect with other Relaticle users.</p>
                        <div class="mt-auto">
                            <a href="{{ route('discord') }}" target="_blank"
                               class="inline-flex items-center text-primary dark:text-primary-400 hover:text-primary-600 dark:hover:text-primary-300 font-medium text-sm transition-colors">
                                Join Discord
                                <svg xmlns="http://www.w3.org/2000/svg" class="ml-1.5 h-4 w-4" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                          d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Documentation -->
                <div class="bg-gray-50 dark:bg-gray-900 p-8 rounded-xl">
                    <div class="flex flex-col h-full">
                        <div
                            class="bg-primary/10 dark:bg-primary/20 p-3 rounded-lg inline-flex w-12 h-12 items-center justify-center mb-5">
                            <x-heroicon-o-document class="w-6 h-6 text-primary dark:text-primary-400" />
                        </div>
                        <h3 class="font-display text-xl font-semibold text-black dark:text-white mb-3">Documentation</h3>
                        <p class="text-gray-600 dark:text-gray-300 mb-6 text-sm">Learn how to use Relaticle and help
                            improve our docs. Comprehensive guides for users and developers alike.</p>
                        <div class="mt-auto">
                            <a href="{{ route('documentation.index') }}"
                               class="inline-flex items-center text-primary dark:text-primary-400 hover:text-primary-600 dark:hover:text-primary-300 font-medium text-sm transition-colors">
                                Read the Docs
                                <svg xmlns="http://www.w3.org/2000/svg" class="ml-1.5 h-4 w-4" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                          d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Community highlights - Simplified -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div
                    class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
                    <div class="text-lg font-semibold text-primary dark:text-primary-400">Open</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Source License</div>
                </div>
                <div
                    class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
                    <div class="text-lg font-semibold text-primary dark:text-primary-400">Active</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Development</div>
                </div>
                <div
                    class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
                    <div class="text-lg font-semibold text-primary dark:text-primary-400">Community</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Driven</div>
                </div>
                <div
                    class="py-3 px-4 text-center border border-gray-100 dark:border-gray-800 rounded-lg bg-white dark:bg-black">
                    <div class="text-lg font-semibold text-primary dark:text-primary-400">Free</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Forever</div>
                </div>
            </div>
        </div>
    </div>
</section>
