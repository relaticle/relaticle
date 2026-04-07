{{-- Full-screen mobile menu --}}
<div x-show="mobileMenu"
     x-transition.opacity.duration.200ms
     @keydown.escape.window="mobileMenu = false"
     class="md:hidden fixed inset-0 z-[60] bg-white dark:bg-gray-950 flex flex-col"
     x-cloak>

    {{-- Header --}}
    <div class="flex items-center justify-between h-16 px-4 shrink-0">
        <a href="{{ url('/') }}" aria-label="Relaticle Home">
            <x-brand.logo-lockup size="md" class="text-black dark:text-white"/>
        </a>
        <button type="button" @click="mobileMenu = false"
                class="p-2 text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-lg transition-colors cursor-pointer"
                aria-label="Close menu">
            <x-ri-close-line class="w-5 h-5"/>
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 flex flex-col justify-center px-8">
        <div class="space-y-1">
            @foreach([
                ['url' => url('/#features'), 'label' => 'Features'],
                ['url' => route('pricing'), 'label' => 'Pricing'],
                ['url' => route('documentation.index'), 'label' => 'Docs'],
                ['url' => route('contact'), 'label' => 'Contact'],
            ] as $link)
                <a href="{{ $link['url'] }}" @click="mobileMenu = false"
                   class="block text-[2rem] font-semibold text-gray-950 dark:text-white hover:text-primary dark:hover:text-primary-400 transition-colors py-2">
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    </nav>

    {{-- Bottom CTA --}}
    <div class="px-8 pb-10 shrink-0">
        <div class="grid grid-cols-2 gap-3">
            <x-marketing.button variant="secondary" href="{{ route('login') }}">
                Sign In
            </x-marketing.button>
            <x-marketing.button href="{{ route('register') }}">
                Start for free
            </x-marketing.button>
        </div>
    </div>
</div>
