@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

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
@endphp

<div class="flex flex-col">

    {{-- ── Internal email banner ──────────────────────────────────────────── --}}
    @if ($record->is_internal && $isOwner)
        <div class="flex items-center gap-2.5 border-b border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-6 py-3 text-sm text-blue-700 dark:text-blue-300">
            <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0" />
            <span class="font-medium">Internal email</span>
            <span class="text-blue-400">—</span>
            <span class="text-blue-600 dark:text-blue-400">visible only to workspace members and hidden from external views.</span>
        </div>
    @endif

    {{-- ── Subject ─────────────────────────────────────────────────────────── --}}
    <div class="border-b border-gray-100 dark:border-gray-800 px-6 pt-5 pb-4">
        <p class="mb-1 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Subject</p>
        @if ($canViewSubject)
            <h2 class="text-xl font-bold leading-snug tracking-tight text-gray-900 dark:text-white">
                {{ $record->subject ?? '(no subject)' }}
            </h2>
        @else
            <p class="text-sm italic text-gray-400 dark:text-gray-500">(subject hidden)</p>
        @endif
    </div>

    {{-- ── Header: avatar · sender · recipients · date · badges · actions ── --}}
    <div class="flex items-start gap-4 border-b border-gray-100 dark:border-gray-800 px-6 py-4">

        {{-- Sender avatar --}}
        <div class="flex h-10 w-10 aspect-square shrink-0 select-none items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/40 text-sm font-semibold text-primary-700 dark:text-primary-300 ring-2 ring-white dark:ring-gray-900">
            {{ $initials ?: '?' }}
        </div>

        {{-- Sender info + recipients --}}
        <div class="min-w-0 flex-1 space-y-1.5">

            {{-- Sender name + email --}}
            <div class="flex flex-wrap items-baseline gap-x-1.5">
                <span class="text-sm font-semibold leading-tight text-gray-900 dark:text-white">
                    {{ $from?->name ?: '(unknown sender)' }}
                </span>
                @if ($from?->email_address)
                    <span class="text-xs text-gray-400 dark:text-gray-500">&lt;{{ $from->email_address }}&gt;</span>
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
                            class="inline-flex cursor-pointer items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-200 dark:ring-gray-700 transition-colors hover:bg-gray-200 dark:hover:bg-gray-700"
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
                            class="inline-flex cursor-pointer items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-200 dark:ring-gray-700 transition-colors hover:bg-gray-200 dark:hover:bg-gray-700"
                        >
                            <span x-show="!showEmail">{{ $recipient->name ?: $recipient->email_address }}</span>
                            <span x-show="showEmail" x-cloak>{{ $recipient->email_address ?: $recipient->name }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

        </div>

        {{-- Right column: date · badges · action icons --}}
        <div class="flex shrink-0 flex-col items-end gap-2.5">

            {{-- Date --}}
            @if ($record->sent_at)
                <time class="whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">
                    {{ $record->sent_at->format('M j, Y · g:i A') }}
                </time>
            @endif

            {{-- Direction + AI badges --}}
            <div class="flex flex-wrap items-center justify-end gap-1.5">
                <span @class([
                    'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                    'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400 dark:ring-sky-400/20'                    => $record->direction === EmailDirection::INBOUND,
                    'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-400 dark:ring-violet-400/20' => $record->direction === EmailDirection::OUTBOUND,
                ])>
                    {{ $record->direction->getLabel() }}
                </span>

                @if ($aiLabel)
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-transparent {{ $aiLabelColor }}">
                        {{ $aiLabel->label }}
                    </span>
                @endif
            </div>

            {{-- Icon-only action group --}}
            @if ($canViewBody)
                <div class="flex items-center divide-x divide-gray-200 dark:divide-gray-700 overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-gray-700">
                    <button
                        type="button"
                        disabled
                        title="Reply (coming soon)"
                        class="flex cursor-not-allowed items-center justify-center bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-200"
                    >
                        <x-heroicon-o-arrow-uturn-left class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled
                        title="Forward (coming soon)"
                        class="flex cursor-not-allowed items-center justify-center bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-200"
                    >
                        <x-heroicon-o-arrow-right class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled
                        title="Assign (coming soon)"
                        class="flex cursor-not-allowed items-center justify-center bg-white dark:bg-gray-800 p-2 text-gray-500 dark:text-gray-400 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-gray-700 dark:hover:text-gray-200"
                    >
                        <x-heroicon-o-user-plus class="h-4 w-4" />
                    </button>
                </div>
            @endif

        </div>
    </div>

    {{-- ── Body ────────────────────────────────────────────────────────────── --}}
    @if ($canViewBody)
        <div class="flex flex-col items-center bg-gray-50 dark:bg-gray-900/30 px-6 py-6">
            <div class="w-full max-w-3xl rounded-xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-950 px-8 py-6 shadow-xs">
                @if ($record->body?->body_html)
                    <div class="prose prose-sm dark:prose-invert max-w-none prose-a:text-primary-600 dark:prose-a:text-primary-400 prose-img:rounded-lg">
                        {!! $record->body->body_html !!}
                    </div>
                @elseif ($record->body?->body_text)
                    <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $record->body->body_text }}</pre>
                @else
                    <p class="text-sm italic text-gray-400 dark:text-gray-500">(no message body)</p>
                @endif
            </div>
        </div>

    {{-- ── Privacy gate ────────────────────────────────────────────────────── --}}
    @else
        <div class="px-6 py-8">
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

    {{-- ── Attachments ─────────────────────────────────────────────────────── --}}
    @if ($canViewBody && $record->has_attachments && $record->attachments->isNotEmpty())
        <div class="border-t border-gray-100 dark:border-gray-800 px-6 py-5">

            <p class="mb-3 text-[10px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">
                Attachments
                <span class="ml-1 font-normal normal-case tracking-normal">
                    ({{ $record->attachments->count() }})
                </span>
            </p>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($record->attachments as $attachment)
                    <div class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 px-3.5 py-3 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-gray-900 shadow-xs ring-1 ring-gray-200 dark:ring-gray-700">
                            <x-heroicon-o-paper-clip class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-gray-800 dark:text-gray-200">
                                {{ $attachment->filename ?? 'Unnamed file' }}
                            </p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                {{ $formatBytes($attachment->size ?? 0) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    @endif

</div>
