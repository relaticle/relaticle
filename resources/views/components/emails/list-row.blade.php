@props(['email', 'selectedEmailId', 'folder'])

@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;

    $isSelected  = $selectedEmailId === $email->id;
    $isUnread    = $email->read_at === null && $email->direction === EmailDirection::INBOUND;
    $from        = $email->from->first();
    $senderName  = $from?->name ?: $from?->email_address ?: '?';
    $authUser    = auth()->user();

    $canViewSubject   = $authUser->can('viewSubject', $email);
    $isOwner          = $email->user_id === $authUser->getKey();
    $canSummarize     = filled($email->thread_id) && $canViewSubject;
    $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
    $hasActions       = $isOwner || $canSummarize || $canRequestAccess;
@endphp

<div x-data="{ actionsOpen: false }" class="relative group">

    <button wire:click="selectEmail('{{ $email->id }}')" @class([
        'w-full text-left px-4 py-3.5 pr-10 transition-colors focus:outline-none',
        'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-primary-500' => $isSelected,
        'hover:bg-gray-50 dark:hover:bg-gray-800/50 border-l-2 border-transparent' => ! $isSelected,
    ])>

        {{-- Sender + meta row --}}
        <div class="flex items-center justify-between gap-2 mb-0.5">
            <div class="flex items-center gap-1.5 min-w-0">
                @if ($isUnread && ! $isSelected)
                    <span class="shrink-0 h-1.5 w-1.5 rounded-full bg-primary-500"></span>
                @endif
                <span @class([
                    'truncate text-sm',
                    'font-semibold text-gray-900 dark:text-white' => $isSelected || $isUnread,
                    'font-normal text-gray-700 dark:text-gray-300' => ! $isSelected && ! $isUnread,
                ])>
                    {{ $senderName }}
                </span>
            </div>
            <div class="flex shrink-0 items-center gap-1.5">
                @if ($folder->value === 'all')
                    @if ($email->direction === EmailDirection::OUTBOUND)
                        <span class="inline-flex items-center rounded-full bg-blue-50 dark:bg-blue-900/20 px-1.5 py-0.5 text-[10px] font-medium text-blue-600 dark:text-blue-400">
                            Sent
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full bg-green-50 dark:bg-green-900/20 px-1.5 py-0.5 text-[10px] font-medium text-green-600 dark:text-green-400">
                            Inbox
                        </span>
                    @endif
                @endif
                <span class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $email->sent_at?->diffForHumans() }}
                </span>
            </div>
        </div>

        {{-- Subject --}}
        <p @class([
            'truncate text-xs',
            'font-medium text-gray-800 dark:text-gray-100' => $isSelected,
            'font-medium text-gray-800 dark:text-gray-200' => $isUnread && ! $isSelected,
            'font-normal text-gray-600 dark:text-gray-400' => ! $isSelected && ! $isUnread,
        ])>
            {{ $canViewSubject ? ($email->subject ?: '(no subject)') : '(subject hidden)' }}
        </p>

        {{-- Snippet --}}
        @if ($email->snippet)
            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">
                {{ $email->snippet }}
            </p>
        @endif

        {{-- Labels --}}
        @if ($email->labels->isNotEmpty())
            <div class="mt-1.5 flex gap-1">
                @foreach ($email->labels->take(2) as $label)
                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">
                        {{ $label->label }}
                    </span>
                @endforeach
            </div>
        @endif

    </button>

    {{-- Per-email actions dropdown --}}
    @if ($hasActions)
        <div class="absolute right-2 top-3">
            <button
                @click.stop="actionsOpen = !actionsOpen"
                type="button"
                class="flex h-6 w-6 items-center justify-center rounded-md text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700"
                :class="{ 'opacity-100 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300': actionsOpen }"
            >
                <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" />
            </button>

            <div
                x-show="actionsOpen"
                @click.outside="actionsOpen = false"
                x-cloak
                class="absolute right-0 top-7 z-50 min-w-[11rem] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1"
            >
                @if ($isOwner)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('manageSharing', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                        <x-heroicon-o-lock-open class="h-4 w-4 shrink-0 text-gray-400" />
                        Sharing
                    </button>
                @endif

                @if ($canSummarize)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('summarizeThread', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                        <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-gray-400" />
                        Summarize Thread
                    </button>
                @endif

                @if ($canRequestAccess)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('requestAccess', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                    >
                        <x-heroicon-o-key class="h-4 w-4 shrink-0 text-gray-400" />
                        Request Access
                    </button>
                @endif
            </div>
        </div>
    @endif

</div>
