@props(['record'])

@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
    use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

    $authUser = auth()->user();

    $from    = $record->participants->firstWhere('role', EmailParticipantRole::FROM);
    $toList  = $record->participants->where('role', EmailParticipantRole::TO);
    $ccList  = $record->participants->where('role', EmailParticipantRole::CC);
    $aiLabel = $record->labels->firstWhere('source', 'ai');

    $canViewSubject = $authUser->can('viewSubject', $record);
    $canViewBody    = $authUser->can('viewBody', $record);
    $isOwner        = $record->user_id === $authUser->getKey();

    $senderName = $from?->name ?: $from?->email_address ?: '?';
    $initials   = collect(explode(' ', trim($senderName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $aiLabelColor = match ($aiLabel?->label) {
        'Scheduling' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300',
        'Marketing'  => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        'Invoice'    => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'Support'    => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
        'Sales'      => 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        default      => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    };

    $formatBytes = fn (int $bytes): string => match (true) {
        $bytes < 1_024         => $bytes . ' B',
        $bytes < 1_048_576     => round($bytes / 1_024, 1) . ' KB',
        default                => round($bytes / 1_048_576, 1) . ' MB',
    };

    $recipientChipClass = 'inline-flex cursor-pointer items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-200 dark:ring-gray-700 transition-colors hover:bg-gray-200 dark:hover:bg-gray-700';
@endphp

{{-- The detail pane owns the full remaining height: the header is fixed and the
     body region is the single scroll container, so the message never renders
     inside a scrollbar-within-a-scrollbar. --}}
<div class="flex min-h-0 flex-1 flex-col">

    {{-- ── Internal email banner ──────────────────────────────────────────── --}}
    @if ($record->is_internal && $isOwner)
        <div class="flex shrink-0 items-center gap-2.5 border-b border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-6 py-2.5 text-sm text-blue-700 dark:text-blue-300">
            <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0" />
            <span class="font-medium">Internal email</span>
            <span class="text-blue-400">—</span>
            <span class="text-blue-600 dark:text-blue-400">visible only to workspace members and hidden from external views.</span>
        </div>
    @endif

    {{-- ── Header: subject · sender · recipients · actions ─────────────────── --}}
    <div class="flex shrink-0 flex-wrap items-start gap-3 border-b border-gray-100 dark:border-gray-800 px-4 py-3.5 sm:gap-4 sm:px-6">

        {{-- Back to the list on narrow viewports, where the two panes alternate --}}
        <button
            wire:click="$set('selectedEmailId', null)"
            type="button"
            aria-label="Back to list"
            class="-ml-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 lg:hidden"
        >
            <x-heroicon-o-arrow-left class="h-5 w-5" />
        </button>

        {{-- Sender avatar --}}
        <div class="hidden h-10 w-10 aspect-square shrink-0 select-none items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/40 text-sm font-semibold text-primary-700 dark:text-primary-300 ring-2 ring-white dark:ring-gray-900 sm:flex">
            {{ $initials ?: '?' }}
        </div>

        <div class="min-w-0 flex-1 space-y-1.5">

            {{-- Subject --}}
            @if ($canViewSubject)
                <h2 class="truncate text-base font-semibold leading-snug tracking-tight text-gray-900 dark:text-white sm:text-lg">
                    {{ $record->subject ?: '(no subject)' }}
                </h2>
            @else
                <p class="text-sm italic text-gray-400 dark:text-gray-500">(subject hidden)</p>
            @endif

            {{-- Sender · date · badges --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="text-sm font-medium leading-tight text-gray-900 dark:text-white">
                    {{ $from?->name ?: '(unknown sender)' }}
                </span>
                @if ($from?->email_address)
                    <span class="truncate text-xs text-gray-400 dark:text-gray-500">&lt;{{ $from->email_address }}&gt;</span>
                @endif

                @if ($record->sent_at)
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    <time class="whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">
                        {{ $record->sent_at->format('M j, Y · g:i A') }}
                    </time>
                @endif

                <span @class([
                    'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                    'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400 dark:ring-sky-400/20'                    => $record->direction === EmailDirection::INBOUND,
                    'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-400 dark:ring-violet-400/20' => $record->direction === EmailDirection::OUTBOUND,
                ])>
                    {{ $record->direction->getLabel() }}
                </span>

                @if ($aiLabel)
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ring-transparent {{ $aiLabelColor }}">
                        {{ $aiLabel->label }}
                    </span>
                @endif
            </div>

            {{-- To recipients --}}
            @if ($toList->isNotEmpty())
                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500">To</span>
                    @foreach ($toList as $recipient)
                        <span
                            x-data="{ showEmail: false }"
                            @click="showEmail = !showEmail"
                            class="{{ $recipientChipClass }}"
                            :title="showEmail ? '{{ $recipient->name }}' : '{{ $recipient->email_address }}'"
                        >
                            <span x-show="!showEmail">{{ $recipient->name ?: $recipient->email_address }}</span>
                            <span x-show="showEmail" x-cloak>{{ $recipient->email_address ?: $recipient->name }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

            {{-- CC recipients --}}
            @if ($ccList->isNotEmpty())
                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                    <span class="text-xs font-medium text-gray-400 dark:text-gray-500">CC</span>
                    @foreach ($ccList as $recipient)
                        <span
                            x-data="{ showEmail: false }"
                            @click="showEmail = !showEmail"
                            class="{{ $recipientChipClass }}"
                        >
                            <span x-show="!showEmail">{{ $recipient->name ?: $recipient->email_address }}</span>
                            <span x-show="showEmail" x-cloak>{{ $recipient->email_address ?: $recipient->name }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

        </div>

        {{-- Actions: Reply is the primary path and stays one click away.
             Too narrow to sit beside the subject? Drop to a row of their own
             rather than clipping off the edge of the pane. --}}
        <div class="flex w-full shrink-0 items-center justify-end gap-1 sm:w-auto">
            @if ($canViewBody)
                @foreach ([
                    'reply'     => ['ri-reply-line', __('filament/pages/email-inbox.reply_forward.modal_headings.reply')],
                    'reply_all' => ['ri-reply-all-line', __('filament/pages/email-inbox.reply_forward.modal_headings.reply_all')],
                    'forward'   => ['ri-share-forward-line', __('filament/pages/email-inbox.reply_forward.modal_headings.forward')],
                ] as $replyMode => [$icon, $label])
                    <button
                        x-on:click="$dispatch('composer:reply', { emailId: '{{ $record->id }}', mode: '{{ $replyMode }}' })"
                        type="button"
                        title="{{ $label }}"
                        aria-label="{{ $label }}"
                        class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                    >
                        <x-filament::icon :icon="$icon" class="h-5 w-5" />
                    </button>
                @endforeach
            @endif

            <x-emails.detail-action-bar :email="$record" />
        </div>
    </div>

    {{-- ── Attachments ─────────────────────────────────────────────────────── --}}
    @if ($canViewBody && $record->has_attachments && $record->attachments->isNotEmpty())
        <div class="flex max-h-28 shrink-0 flex-wrap items-center gap-2 overflow-y-auto border-b border-gray-100 dark:border-gray-800 px-4 py-2.5 sm:px-6">
            <x-heroicon-o-paper-clip class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />

            @foreach ($record->attachments as $attachment)
                @php
                    $downloadUrl = filled($attachment->provider_attachment_id)
                        ? route('email-attachments.download', $attachment->getKey())
                        : null;
                @endphp

                <a
                    @if ($downloadUrl) href="{{ $downloadUrl }}" download @endif
                    @class([
                        'flex max-w-56 items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 px-2.5 py-1 transition-colors',
                        'hover:bg-gray-100 dark:hover:bg-gray-800' => $downloadUrl,
                        'pointer-events-none opacity-60' => ! $downloadUrl,
                    ])
                >
                    <span class="truncate text-xs font-medium {{ $downloadUrl ? 'text-primary-700 dark:text-primary-300' : 'text-gray-800 dark:text-gray-200' }}">
                        {{ $attachment->filename ?? 'Unnamed file' }}
                    </span>
                    <span class="shrink-0 text-[11px] text-gray-400 dark:text-gray-500">
                        {{ $downloadUrl ? $formatBytes($attachment->size ?? 0) : 'processing…' }}
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── Body ────────────────────────────────────────────────────────────── --}}
    @if ($canViewBody)
        @php
            $safeHtml = EmailHtmlSanitizer::sanitize($record->body?->body_html);
        @endphp

        @if ($safeHtml !== null)
            {{-- The iframe fills the region and scrolls itself. The sandbox has no
                 allow-scripts/allow-same-origin, so its height cannot be measured
                 from here — filling the pane is what keeps a three-line email from
                 rendering into a viewport of blank space. --}}
            <div class="min-h-0 flex-1 bg-white dark:bg-gray-950">
                <iframe
                    srcdoc="{{ $safeHtml }}"
                    sandbox="allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                    class="h-full w-full border-0"
                ></iframe>
            </div>
        @else
            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                @if ($record->body?->body_text)
                    <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $record->body->body_text }}</pre>
                @else
                    <p class="text-sm italic text-gray-400 dark:text-gray-500">(no message body)</p>
                @endif
            </div>
        @endif

    {{-- ── Privacy gate ────────────────────────────────────────────────────── --}}
    @else
        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-8">
            <div class="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 px-8 py-12 text-center">

                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                    <x-heroicon-o-lock-closed class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                </div>

                <div class="space-y-1">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                        @if ($record->privacy_tier === EmailPrivacyTier::METADATA_ONLY)
                            Email body and subject are restricted
                        @elseif ($record->privacy_tier === EmailPrivacyTier::SUBJECT)
                            Email body is restricted
                        @else
                            This email is private
                        @endif
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        @if ($record->privacy_tier === EmailPrivacyTier::METADATA_ONLY)
                            You can see participant and date information. Request access to view the subject and body.
                        @elseif ($record->privacy_tier === EmailPrivacyTier::SUBJECT)
                            You can see the subject line. The full email body is hidden. Request access to see more.
                        @else
                            Only the email owner can view this content.
                        @endif
                    </p>
                </div>

                @if ($authUser->can('requestAccess', $record))
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Use <span class="font-semibold text-gray-600 dark:text-gray-300">Request Access</span> from the row actions to ask for expanded access.
                    </p>
                @endif

            </div>
        </div>
    @endif

    {{-- ── Draft composer ──────────────────────────────────────────────────
         The real composer, docked under the message it answers rather than
         floating over it: same fields, toolbar, attachments, templates and
         signatures as Compose. The reply icons above open it. --}}
    @if ($canViewBody)
        @livewire('email-integration.composer', ['dock' => 'inline'], key('inline-reply-composer'))
    @endif

</div>
