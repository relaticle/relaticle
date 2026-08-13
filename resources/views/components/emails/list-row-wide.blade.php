@props(['email', 'folder'])

{{-- The full-width inbox row: one line per email, the way a mail list is meant to
     scan. The narrow variant ({@see list-row}) still serves the record pages, whose
     list lives in a 320px column and has to stack. --}}
@php
    use Relaticle\EmailIntegration\Enums\EmailCategory;
    use Relaticle\EmailIntegration\Enums\EmailDirection;

    // `is_read` is a per-viewer flag set by the withReadStateFor() query scope.
    $isUnread   = ! $email->is_read && $email->direction === EmailDirection::INBOUND;
    $from       = $email->from->first();
    $senderName = $from?->name ?: $from?->email_address ?: '?';
    $authUser   = auth()->user();

    $canViewSubject   = $authUser->can('viewSubject', $email);
    $isOwner          = $email->user_id === $authUser->getKey();
    $canSummarize     = $isOwner || $authUser->can('viewBody', $email);
    $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
    $hasActions       = $isOwner || $canSummarize || $canRequestAccess;

    // A column of "1 day ago" is unscannable; mail lists read by clock, weekday, date.
    $sentAt = $email->sent_at;
    $timestamp = match (true) {
        $sentAt === null          => null,
        $sentAt->isToday()        => $sentAt->format('g:i A'),
        $sentAt->isYesterday()    => 'Yesterday',
        $sentAt->diffInDays() < 7 => $sentAt->format('D'),
        $sentAt->isCurrentYear()  => $sentAt->format('M j'),
        default                   => $sentAt->format('M j, Y'),
    };

    $category = EmailCategory::tryFrom($email->labels->first()?->label ?? '');

    $initials = collect(explode(' ', trim($senderName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    // "Google, someone@example.com" — who the message is between, in one line.
    $participantLine = $email->participants
        ->map(fn ($participant): string => (string) ($participant->name ?: $participant->email_address))
        ->filter()
        ->unique()
        ->take(4)
        ->implode(', ');
@endphp

<div x-data="{ actionsOpen: false }" class="group relative">

    <button
        wire:click="selectEmail('{{ $email->id }}')"
        type="button"
        @class([
            'flex w-full items-start gap-3 px-4 py-3 pr-24 text-left transition-colors focus:outline-none sm:px-6',
            'hover:bg-gray-50 dark:hover:bg-gray-800/50',
            'bg-primary-50/40 dark:bg-primary-900/10' => $isUnread,
        ])
    >
        {{-- Sender avatar, doubling as the unread marker --}}
        <span class="relative shrink-0">
            <span class="flex h-7 w-7 select-none items-center justify-center rounded-full bg-gray-100 text-[11px] font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                {{ $initials ?: '?' }}
            </span>
            @if ($isUnread)
                <span data-unread-indicator class="absolute -left-1 top-1/2 h-1.5 w-1.5 -translate-y-1/2 rounded-full bg-primary-500"></span>
            @endif
        </span>

        <span class="min-w-0 flex-1">
            {{-- Subject first: it is what the row is actually about --}}
            <span class="flex items-baseline gap-3">
                <span @class([
                    'min-w-0 flex-1 truncate text-sm',
                    'font-semibold text-gray-900 dark:text-white' => $isUnread,
                    'font-medium text-gray-800 dark:text-gray-200' => ! $isUnread,
                ])>
                    {{ $canViewSubject ? ($email->subject ?: '(no subject)') : '(subject hidden)' }}
                </span>

                <span class="flex shrink-0 items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                    @if ($email->has_attachments)
                        <x-heroicon-m-paper-clip class="h-3.5 w-3.5" />
                    @endif
                    @if ($category)
                        <span
                            @class(['hidden h-1.5 w-1.5 rounded-full lg:block', 'fi-color-'.$category->getColor()])
                            style="background-color: var(--color-400)"
                            title="{{ $category->getLabel() }}"
                        ></span>
                    @endif
                    @if ($folder->value === 'all')
                        <span class="hidden sm:inline">
                            {{ $email->direction === EmailDirection::OUTBOUND ? 'Sent' : 'Inbox' }}
                        </span>
                    @endif
                    <time class="tabular-nums" title="{{ $sentAt?->format('M j, Y · g:i A') }}">{{ $timestamp }}</time>
                </span>
            </span>

            {{-- Who it is between --}}
            <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
                {{ $participantLine }}
            </span>

            {{-- Preview --}}
            @if ($email->snippet)
                <span class="mt-1 block truncate text-sm text-gray-400 dark:text-gray-500">
                    {{ $email->snippet }}
                </span>
            @endif
        </span>
    </button>

    {{-- Per-email actions, revealed on hover over the row --}}
    @if ($hasActions)
        <div class="absolute right-4 top-1/2 -translate-y-1/2 sm:right-6">
            <button
                @click.stop="actionsOpen = !actionsOpen"
                type="button"
                aria-label="{{ __('filament/pages/email-inbox.row_actions.label') }}"
                class="flex h-7 w-7 items-center justify-center rounded-md text-gray-400 opacity-0 transition-opacity hover:bg-gray-100 hover:text-gray-600 group-hover:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-300"
                :class="{ 'opacity-100 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300': actionsOpen }"
            >
                <x-heroicon-o-ellipsis-horizontal class="h-4 w-4" />
            </button>

            <div
                x-show="actionsOpen"
                @click.outside="actionsOpen = false"
                x-cloak
                class="absolute right-0 top-8 z-20 min-w-[11rem] rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
            >
                @if ($isOwner)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('manageSharing', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                    >
                        <x-ri-share-line class="h-4 w-4 shrink-0 text-gray-400" />
                        {{ __('filament/pages/email-inbox.sharing.label') }}
                    </button>
                @endif

                @if ($canSummarize)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('summarizeThread', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                    >
                        <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-gray-400" />
                        {{ __('filament/pages/email-inbox.summarize_thread.label') }}
                    </button>
                @endif

                @if ($canRequestAccess)
                    <button
                        @click.stop="actionsOpen = false; $wire.mountAction('requestAccess', { emailId: '{{ $email->id }}' })"
                        type="button"
                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700/50"
                    >
                        <x-heroicon-o-key class="h-4 w-4 shrink-0 text-gray-400" />
                        {{ __('filament/pages/email-inbox.request_access.label') }}
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
