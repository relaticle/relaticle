@props([
    'message',
    'summary',
    'steps',
    'workspace' => null,
    'width' => 'max-w-lg',
])

<div {{ $attributes->class('bg-dots flex h-full min-h-[inherit] items-center justify-center p-4 sm:p-8 md:p-12') }}>
    <x-cloud::marketing.chat-window class="w-full {{ $width }}" :workspace="$workspace">
        <div class="space-y-3 p-4 sm:p-5">
            <p class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md bg-gray-100 px-4 py-2.5 text-sm leading-relaxed text-pretty text-gray-900 dark:bg-white/10 dark:text-gray-100">
                {{ $message }}
            </p>

            <p class="px-1 text-sm leading-relaxed text-gray-900 dark:text-gray-100">{{ $summary }}</p>

            <x-cloud::marketing.app-plan :steps="$steps"/>
        </div>
    </x-cloud::marketing.chat-window>
</div>
