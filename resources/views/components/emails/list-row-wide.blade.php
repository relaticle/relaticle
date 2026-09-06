@props(['email'])

{{-- Record mailbox row: subject, participants, snippet, via the connected
     mailbox that imported the email, and an AI category pill. When the viewer
     lacks body access, the snippet is replaced by a request-access pill. --}}
@php
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

    $from       = $email->from->first();
    $senderName = $from?->name ?: $from?->email_address ?: '?';
    $authUser   = auth()->user();
    $categoryLabel = $email->categoryLabel();
    $isReply    = filled($email->in_reply_to);
    $mailboxViaName = $email->mailboxViaName();

    $canViewSubject = $authUser->can('viewSubject', $email);
    $canViewBody    = $authUser->can('viewBody', $email);
    $canRequestAccess = $authUser->cannot('viewBody', $email) && $authUser->can('requestAccess', $email);
    $hasPendingAccessRequest = (bool) ($email->viewer_has_pending_access_request ?? false);
    $ownerName = $email->user?->name ?: $email->user?->email ?: __('filament/pages/email-inbox.pending_access.unknown_user');

    $sentAt = $email->sent_at;
    $timestamp = match (true) {
        $sentAt === null          => null,
        $sentAt->isToday()        => $sentAt->format('g:i A'),
        $sentAt->isYesterday()    => __('filament/pages/email-inbox.list_row.timestamp_yesterday', ['time' => $sentAt->format('g:i A')]),
        $sentAt->diffInDays() < 7 => $sentAt->format('D'),
        $sentAt->isCurrentYear()  => $sentAt->format('M j'),
        default                   => $sentAt->format('M j, Y'),
    };

    $participantLine = collect([EmailParticipantRole::FROM, EmailParticipantRole::TO])
        ->flatMap(fn (EmailParticipantRole $role) => $email->participants->where('role', $role))
        ->map(fn ($participant): string => (string) ($participant->name ?: $participant->email_address))
        ->filter()
        ->unique()
        ->take(4)
        ->implode(', ');

    $participantLine = $participantLine ?: $senderName;

    $avatarColor = ['primary', 'success', 'warning', 'danger', 'info'][crc32(mb_strtolower($senderName)) % 5];

    $initials = collect(explode(' ', trim($senderName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $decode = fn (?string $text): string => html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $subject = $decode($email->subject);

    $snippet = $canViewBody
        ? trim(preg_replace('/^\s*\S+@\S+\.\S+\s*/u', '', $decode($email->snippet)) ?? '')
        : '';

    $rowTag = $canViewBody ? 'button' : 'div';
@endphp
<{{ $rowTag }}
    @if ($canViewBody)
        type="button"
        wire:click="selectEmail('{{ $email->id }}')"
        wire:loading.attr="disabled"
        wire:target="selectEmail('{{ $email->id }}')"
    @endif
    {{ $attributes->class([
        'ei-email-list-row relative flex w-full items-start gap-3 bg-white px-4 py-3 text-left transition-colors hover:bg-gray-50 dark:bg-gray-950 dark:hover:!bg-gray-900 sm:px-6',
        'cursor-pointer data-[loading]:cursor-wait data-[loading]:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:data-[loading]:bg-gray-900' => $canViewBody,
        'cursor-default' => ! $canViewBody,
    ]) }}
>
    @if ($canViewBody)
        <span
            wire:loading.flex
            wire:target="selectEmail('{{ $email->id }}')"
            class="pointer-events-none absolute inset-0 z-10 items-center justify-center bg-white/70 dark:bg-gray-950/70"
            role="status"
            aria-label="{{ __('filament/pages/email-inbox.list_row.opening') }}"
        >
            <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
        </span>
    @endif

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
        <span class="flex items-center justify-between gap-3">
            <span class="min-w-0 flex-1 truncate text-sm font-medium leading-5 text-gray-800 dark:text-gray-200">
                {{ $canViewSubject ? ($subject ?: '(no subject)') : '(subject hidden)' }}
            </span>

            <span class="flex shrink-0 items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                @if (filled($mailboxViaName))
                    <span class="flex min-w-0 items-center gap-1 text-gray-500 dark:text-gray-400">
                        <x-heroicon-m-envelope class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                        <span class="max-w-[7rem] truncate">{{ __('filament/pages/email-inbox.list_row.via', ['name' => $mailboxViaName]) }}</span>
                    </span>
                @endif

                <span class="flex items-center gap-1.5 whitespace-nowrap">
                    @if ($email->has_attachments)
                        <x-heroicon-m-paper-clip class="h-3.5 w-3.5" aria-hidden="true" />
                    @endif
                    <time
                        class="tabular-nums"
                        title="{{ $sentAt?->format('M j, Y · g:i A') }}"
                    >{{ $timestamp }}</time>
                </span>
            </span>
        </span>

        <span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">
            {{ $participantLine }}
        </span>

        @if ($canViewBody && (filled($snippet) || $categoryLabel !== null))
            <span class="mt-0.5 flex items-center justify-between gap-3">
                @if (filled($snippet))
                    <span class="flex min-w-0 items-center gap-1 truncate text-xs leading-5 text-gray-500 dark:text-gray-400">
                        @if ($isReply)
                            <x-ri-reply-line class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                        @else
                            <x-ri-file-text-line class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                        @endif
                        <span class="truncate">{{ $snippet }}</span>
                    </span>
                @else
                    <span class="min-w-0 flex-1"></span>
                @endif

                @if ($categoryLabel !== null)
                    <x-emails.category-badge :label="$categoryLabel->label" class="ml-auto" />
                @endif
            </span>
        @endif

        @if ($canRequestAccess)
            <x-emails.request-access-list-pill
                :email="$email"
                :owner-name="$ownerName"
                :requested="$hasPendingAccessRequest"
            />
        @endif
    </span>
</{{ $rowTag }}>
