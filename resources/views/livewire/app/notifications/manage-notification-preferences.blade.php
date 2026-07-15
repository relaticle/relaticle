<div class="space-y-8">
    {{-- Daily digest --}}
    <section>
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('notifications.digest.heading') }}
        </h2>

        <div class="mt-3 rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-white/10 dark:bg-white/5">
            <label class="flex items-center justify-between gap-4">
                <span>
                    <span class="block text-sm font-medium text-gray-950 dark:text-white">
                        {{ __('notifications.digest.title') }}
                    </span>
                    <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">
                        {{ __('notifications.digest.description') }}
                    </span>
                </span>

                <button
                    type="button"
                    role="switch"
                    :aria-checked="$wire.digestEnabled"
                    wire:click="$set('digestEnabled', ! $wire.digestEnabled)"
                    x-bind:class="$wire.digestEnabled ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10'"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors"
                >
                    <span
                        x-bind:class="$wire.digestEnabled ? 'translate-x-5' : 'translate-x-0'"
                        class="pointer-events-none inline-block h-5 w-5 translate-y-0.5 transform rounded-full bg-white shadow transition-transform"
                    ></span>
                </button>
            </label>
        </div>
    </section>

    {{-- Collaboration matrix --}}
    <section>
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
            {{ __('notifications.collaboration.heading') }}
        </h2>

        <div class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 bg-white dark:divide-white/10 dark:border-white/10 dark:bg-white/5">
            <div class="flex items-center gap-4 px-4 py-3">
                <div class="flex flex-1 items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="ri-notification-3-line" class="size-4" />
                    {{ __('notifications.collaboration.notify_me_about') }}
                </div>
                @foreach(\App\Enums\Notifications\NotificationChannel::cases() as $channel)
                    <div class="flex w-16 items-center justify-center gap-1.5 text-sm font-medium text-gray-950 dark:text-white">
                        <x-filament::icon :icon="$channel->icon()" class="size-4" />
                        {{ $channel->label() }}
                    </div>
                @endforeach
            </div>

            @foreach($types as $type)
                <div class="flex items-center gap-4 px-4 py-4">
                    <div class="flex-1">
                        <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $type->label() }}</span>
                        <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">{{ $type->description() }}</span>
                    </div>

                    @foreach(\App\Enums\Notifications\NotificationChannel::cases() as $channel)
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
    </section>
</div>
