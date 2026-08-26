<div class="space-y-6">
    {{-- Daily digest --}}
    <x-filament::section :heading="__('notifications.digest.heading')">
        <div class="flex items-center justify-between gap-4">
            <div>
                <span class="block text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('notifications.digest.title') }}
                </span>
                <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">
                    {{ __('notifications.digest.description') }}
                </span>
            </div>

            <x-filament::toggle state="$wire.$entangle('digestEnabled').live" />
        </div>
    </x-filament::section>

    {{-- Collaboration matrix --}}
    <x-filament::section :heading="__('notifications.collaboration.heading')">
        <div class="-mx-6 -my-4 divide-y divide-gray-200 dark:divide-white/10">
            <div class="flex items-center gap-4 px-6 py-3">
                <div class="flex flex-1 items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="ri-notification-3-line" class="size-4" />
                    {{ __('notifications.collaboration.notify_me_about') }}
                </div>
                @foreach($channels as $channel)
                    <div class="flex w-16 items-center justify-center gap-1.5 text-sm font-medium text-gray-950 dark:text-white">
                        <x-filament::icon :icon="$channel->icon()" class="size-4" />
                        {{ $channel->label() }}
                    </div>
                @endforeach
            </div>

            @foreach($types as $type)
                <div class="flex items-center gap-4 px-6 py-4">
                    <div class="flex-1">
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $type->label() }}</span>
                        <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">{{ $type->description() }}</span>
                    </div>

                    @foreach($channels as $channel)
                        <div class="flex w-16 justify-center">
                            @if(in_array($channel, $type->channels(), true))
                                <x-filament::input.checkbox
                                    wire:model.live="cells.{{ $type->value }}.{{ $channel->value }}"
                                />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </x-filament::section>
</div>
