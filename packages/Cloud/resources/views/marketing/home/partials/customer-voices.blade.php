@php
    $testimonials = collect(config('marketing.testimonials', []))->reject(fn (array $testimonial): bool => isset($testimonial['slot']))->take(3)->values()->all();
    $logos = config('marketing.logos', []);
@endphp

@if($testimonials !== [] || $logos !== [])
    <x-cloud::marketing.frame id="customers">
        <x-cloud::marketing.frame-header :label="__('Customers')" :lead="__('Founders who sell with it.')">
            {{ __('In their words, with their names.') }}
        </x-cloud::marketing.frame-header>

        <x-cloud::marketing.logo-wall :logos="$logos"/>

        @if($testimonials !== [])
            <div @class(['bg-rail grid grid-cols-1 gap-px md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]', 'border-rail border-t' => $logos !== []])>
                @foreach($testimonials as $testimonial)
                    <x-cloud::marketing.quote
                        class="p-6 md:p-8"
                        :quote="$testimonial['quote']"
                        :name="$testimonial['name']"
                        :role="$testimonial['role']"
                        :company="$testimonial['company']"
                        :url="$testimonial['url'] ?? null"
                        :avatar="$testimonial['avatar'] ?? null"
                    />
                @endforeach
            </div>
        @endif
    </x-cloud::marketing.frame>
@endif
