@php
    $steps = [
        [__('Connect your inbox'), __('Link a Google or Microsoft account. Relaticle never changes or deletes a message in your mailbox.')],
        [__('Records fill in'), __('People you email are added for you, with their companies. Each email and meeting lands on the record it involves.')],
        [__('You choose who sees what'), __('Share who you talked to and when, the subject, or the full email. A teammate can ask for more.')],
    ];
@endphp

<x-cloud::marketing.frame id="email">
    <x-cloud::marketing.frame-header :label="__('Email and calendar')" :lead="__('Your inbox fills in your CRM.')">
        {{ __('Every email and meeting lands on the record it involves.') }}
    </x-cloud::marketing.frame-header>

    <div class="bg-rail grid grid-cols-1 gap-px lg:grid-cols-12">
        <div class="bg-tint bg-warm feat-card flex items-center justify-center p-4 sm:p-8 md:p-12 lg:col-span-8">
            <x-cloud::marketing.record-emails-mock class="w-full max-w-xl"/>
        </div>

        <div class="bg-rail grid gap-px lg:col-span-4">
            @foreach($steps as $index => [$title, $description])
                <div class="bg-surface p-6 md:p-8">
                    <x-cloud::marketing.eyebrow class="mb-3">{{ sprintf('%02d', $index + 1) }}</x-cloud::marketing.eyebrow>
                    <h3 class="text-base font-medium text-gray-950 dark:text-white">{{ $title }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">{{ $description }}</p>
                </div>
            @endforeach

            <a href="{{ route('emailAndCalendar') }}" class="bg-surface hover:bg-tint group flex items-center justify-between px-6 py-5 text-sm font-medium text-gray-950 transition-colors md:px-8 dark:text-white">
                {{ __('How email works in Relaticle') }}
                <x-ri-arrow-right-line class="h-4 w-4 transition-transform group-hover:translate-x-0.5"/>
            </a>
        </div>
    </div>
</x-cloud::marketing.frame>
