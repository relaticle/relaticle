@props(['email', 'folder', 'ownAddresses' => []])

{{-- The full-width row: one line per email, the way a mail list is meant to scan.
     Used by every mail list — the inbox board and the record pages. --}}
@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

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

    // Filament's palette, picked by a stable hash of the sender so the same
    // correspondent keeps the same colour between renders and pages.
    $avatarColor = ['primary', 'success', 'warning', 'danger', 'info'][crc32(mb_strtolower($senderName)) % 5];

    $initials = collect(explode(' ', trim($senderName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $participantLine = collect([EmailParticipantRole::FROM, EmailParticipantRole::TO])
        ->flatMap(fn (EmailParticipantRole $role) => $email->participants->where('role', $role))
        ->map(fn ($participant): string => (string) ($participant->name ?: $participant->email_address))
        ->filter()
        ->unique()
        ->take(4)
        ->implode(', ');

    $participantLine = $participantLine ?: $senderName;

    // Snippets and subjects arrive holding HTML entities from the source message.
    // Blade escapes on output, so without decoding first the row literally reads
    // "You&#39;ve found" instead of "You've found".
    $decode = fn (?string $text): string => html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $subject = $decode($email->subject);

    // Provider snippets open with preheader text, most often the recipient's own
    // address — so the preview began by telling the reader their own email instead
    // of the first words of the message.
    $snippet = trim(preg_replace('/^\s*\S+@\S+\.\S+\s*/u', '', $decode($email->snippet)) ?? '');

@endphp

<div class="group relative">

    <button
        wire:click="selectEmail('{{ $email->id }}')"
        type="button"
        @if ($isUnread) data-unread-indicator @endif
        @class([
            'flex w-full items-start gap-3 px-4 py-3 text-left transition-colors sm:px-6',
            // Keyboard users need to see where they are; the row is the primary control.
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500',
            'bg-primary-50/40 hover:bg-primary-50 dark:bg-primary-950 dark:hover:!bg-primary-900' => $isUnread,
            'bg-white hover:bg-gray-50 dark:bg-gray-950 dark:hover:!bg-gray-900' => ! $isUnread,
        ])
    >
        <span class="mt-2 flex h-2 w-2 shrink-0 items-center justify-center">
            @if ($isUnread)
                <span class="h-2 w-2 rounded-full bg-primary-500 dark:bg-primary-400"></span>
            @endif
        </span>

        {{-- Sender avatar --}}
        <span
            @class([
                'flex h-8 w-8 shrink-0 select-none items-center justify-center rounded-full text-[11px] font-semibold ring-1 ring-inset ring-white/60 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10',
                '[background-color:color-mix(in_oklab,var(--color-500)_14%,transparent)] [color:var(--color-600)]',
                'fi-color-'.$avatarColor,
            ])
        >
            {{ $initials ?: '?' }}
        </span>

        <span class="min-w-0 flex-1">
            {{-- Subject first: it is what the row is actually about --}}
            <span class="flex items-start gap-3">
                <span @class([
                    'min-w-0 flex-1 truncate text-sm leading-5',
                    'font-semibold text-gray-900 dark:text-white' => $isUnread,
                    'font-medium text-gray-800 dark:text-gray-200' => ! $isUnread,
                ])>
                    {{ $canViewSubject ? ($subject ?: '(no subject)') : '(subject hidden)' }}
                </span>

                {{-- Only the All folder mixes directions. The badge sits with the
                     subject it describes; in the timestamp cluster it read as another
                     date-ish token. --}}
                @if ($folder->value === 'all')
                    @php $isOutbound = $email->direction === EmailDirection::OUTBOUND; @endphp

                    <x-filament::badge
                        :color="$isOutbound ? 'info' : 'success'"
                        size="xs"
                        class="fi-email-direction-badge hidden shrink-0 sm:inline-flex"
                    >
                        {{ $isOutbound
                            ? __('filament/pages/email-inbox.folders.sent')
                            : __('filament/pages/email-inbox.folders.inbox') }}
                    </x-filament::badge>
                @endif

                <span class="flex shrink-0 items-center gap-2 pt-0.5 text-xs text-gray-400 dark:text-gray-400">
                    @if ($email->has_attachments)
                        <x-heroicon-m-paper-clip class="h-3.5 w-3.5" />
                    @endif
                    <time class="w-[4.5rem] shrink-0 text-right tabular-nums max-sm:w-auto" title="{{ $sentAt?->format('M j, Y · g:i A') }}">{{ $timestamp }}</time>
                </span>
            </span>

            {{-- Participants sit directly below the subject, Gmail-style: compact
                 context without turning each row into a labeled detail view. --}}
            <span class="mt-0.5 block truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ $participantLine }}
            </span>

            {{-- Preview --}}
            @if (filled($snippet))
                <span class="mt-0.5 block truncate text-xs leading-5 text-gray-500 dark:text-gray-400">
                    {{ $snippet }}
                </span>
            @endif
        </span>
    </button>

</div>
