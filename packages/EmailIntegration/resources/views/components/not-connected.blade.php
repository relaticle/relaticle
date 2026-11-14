@props([
    'state',
    'action',
    'secondaryAction' => null,
    'sharingUrl' => null,
    'compact' => false,
])

@php
    use Relaticle\Documentation\Support\DocUrl;
    use Relaticle\EmailIntegration\Enums\MailboxConnectState;
@endphp

<div
    data-connect-card="{{ $state->value }}"
    @class([
        'flex flex-col gap-3',
        'border-b border-gray-200/60 px-4 py-4 sm:flex-row sm:items-center lg:px-6 dark:border-white/10' => $compact,
        'items-center justify-center px-8 py-16 text-center' => ! $compact,
    ])
>
    <div @class([
        'flex shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800',
        'h-10 w-10' => $compact,
        'h-16 w-16' => ! $compact,
    ])>
        <x-filament::icon :icon="$state->icon()" @class(['text-gray-400 dark:text-gray-500', 'h-5 w-5' => $compact, 'h-8 w-8' => ! $compact]) />
    </div>

    <div @class(['min-w-0 flex-1' => $compact, 'flex flex-col items-center gap-3' => ! $compact])>
        <p @class(['font-semibold text-gray-900 dark:text-white', 'text-sm' => $compact, 'text-base' => ! $compact])>{{ $state->heading() }}</p>

        <p @class(['text-sm text-gray-500 dark:text-gray-400', 'max-w-sm' => ! $compact])>{{ $state->description() }}</p>
    </div>

    <div @class(['flex flex-wrap gap-3', 'mt-2 justify-center' => ! $compact])>
        @switch($state)
            @case(MailboxConnectState::NoProvider)
                <x-filament::button tag="a" :href="DocUrl::docsPage('self-hosting').'#email-and-calendar'" target="_blank" color="gray" outlined icon="lucide-book-open">
                    {{ __('filament/pages/email-accounts.connect_card.no_provider.guide') }}
                </x-filament::button>
                @break

            @case(MailboxConnectState::SharingUnanswered)
                @if ($sharingUrl)
                    <x-filament::button tag="a" :href="$sharingUrl">
                        {{ __('filament/pages/email-accounts.connect_card.sharing_unanswered.choose') }}
                    </x-filament::button>
                @endif
                @break

            @default
                {{ $action }}

                @if ($secondaryAction?->isVisible())
                    {{ $secondaryAction }}
                @endif
        @endswitch
    </div>
</div>
