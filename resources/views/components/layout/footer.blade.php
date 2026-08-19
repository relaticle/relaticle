<!-- Minimalist Footer -->
<footer class="py-12 md:py-16 border-t border-gray-100 dark:border-gray-900 bg-white dark:bg-gray-950">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div
            class="grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-8 pb-10 border-b border-gray-100 dark:border-gray-900">
            <!-- Company Info -->
            <div class="md:col-span-4 space-y-5">
                <a href="{{ url('/') }}" class="inline-flex w-fit" aria-label="Relaticle Home">
                    <x-brand.logo-lockup size="md" class="text-black dark:text-white" />
                </a>
                <p class="text-gray-500 dark:text-gray-400 text-sm leading-relaxed max-w-md">
                    The open-source CRM built for people and AI-powered work. Self-hosted. No per-seat pricing. Yours to own.
                </p>

                <!-- Social links - Simplified -->
                <div class="flex space-x-4">
                    <a href="https://github.com/relaticle/relaticle" target="_blank" rel="noopener noreferrer"
                       class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="GitHub">
                        <x-ri-github-fill class="h-5 w-5" />
                    </a>
                    <a href="{{ route('discord') }}" target="_blank" rel="noopener noreferrer"
                       class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="Discord">
                        <x-ri-discord-fill class="h-5 w-5" />
                    </a>
                    <a href="https://x.com/relaticle" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="X">
                        <x-ri-twitter-x-fill class="h-5 w-5" />
                    </a>
                    <a href="https://www.linkedin.com/company/relaticle" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-primary dark:hover:text-primary-400 transition-colors"
                       aria-label="LinkedIn">
                        <x-ri-linkedin-box-fill class="h-5 w-5" />
                    </a>
                </div>
            </div>

            @php($columns = app(\App\Support\MarketingNavigation::class)->footer())

            <nav aria-label="{{ __('Footer') }}" class="md:col-span-8 grid grid-cols-2 md:grid-cols-4 gap-8">
                @foreach($columns as $column)
                    <div>
                        <h3 class="font-medium text-xs text-black dark:text-white uppercase tracking-wider mb-4">
                            {{ $column->label }}
                        </h3>
                        <ul class="space-y-3">
                            @foreach($column->children as $item)
                                <li>
                                    <a href="{{ $item->url }}"
                                       @if($item->external) target="_blank" rel="noopener noreferrer" @endif
                                       @if(url()->current() === $item->url) aria-current="page" @endif
                                       class="text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary-400 text-sm transition-colors">
                                        {{ $item->label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </div>

        <!-- Copyright section -->
        <div class="mt-8 flex flex-col md:flex-row md:justify-between items-center gap-4">
            <div class="flex items-center gap-4">
                <p class="text-gray-500 dark:text-gray-400 text-xs">&copy; {{ date('Y') }} Relaticle. All rights
                    reserved.</p>

                <a href="{{ route('llms-txt') }}"
                   class="text-gray-500 dark:text-gray-400 text-xs hover:text-primary dark:hover:text-primary-400 transition-colors">llms.txt</a>
            </div>

            <x-theme-switcher />
        </div>
    </div>
</footer>
