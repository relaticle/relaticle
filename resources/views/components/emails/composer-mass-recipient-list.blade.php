@props([
    'recipients' => [],
])

@php
    use App\Services\AvatarService;

    $removeLabel = __('filament/emails/composer.actions.remove_recipient');

    $recipientTooltip = static function (string $name, string $email): string {
        return $name === $email ? $email : "{$name} · {$email}";
    };
@endphp

<ul {{ $attributes->class(['min-h-0 flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-white/5']) }}>
    @forelse ($recipients as $recipient)
        <li wire:key="mass-recipient-{{ $recipient['personId'] }}" class="flex items-center gap-3 px-3 py-2.5">
            <x-filament::avatar
                :src="resolve(AvatarService::class)->generate($recipient['name'])"
                :alt="$recipient['name']"
                size="h-8 w-8"
                class="shrink-0"
            />
            <div
                class="min-w-0 flex-1"
                x-tooltip="{ content: @js($recipientTooltip($recipient['name'], $recipient['email'])), theme: $store.theme }"
            >
                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $recipient['name'] }}</p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $recipient['email'] }}</p>
            </div>
            <x-emails.composer-icon-button
                icon="heroicon-m-x-mark"
                :label="$removeLabel"
                class="!p-1"
                wire:click="removeMassRecipient('{{ $recipient['personId'] }}')"
            />
        </li>
    @empty
        <li class="px-3 py-6 text-center text-xs text-gray-400 dark:text-gray-500">
            {{ __('filament/emails/composer.mass_send.add_recipients') }}
        </li>
    @endforelse
</ul>
