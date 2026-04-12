@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

    $authUser    = auth()->user();
    $firstEmail  = $emails->first();
    $threadCount = $emails->count();
@endphp

<div class="flex flex-col min-h-full">

    {{-- ── Thread subject bar ──────────────────────────────────────── --}}
    @if ($firstEmail && $threadCount > 1)
        <div class="flex shrink-0 items-center gap-3 border-b border-gray-100 dark:border-gray-800 bg-gray-50/80 dark:bg-gray-900/60 px-6 py-2.5">
            <p class="flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ $authUser->can('viewSubject', $firstEmail) ? ($firstEmail->subject ?? '(no subject)') : '(subject hidden)' }}
            </p>
            <span class="shrink-0 inline-flex items-center rounded-full bg-gray-200 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                {{ $threadCount }} messages
            </span>
        </div>
    @endif

    {{-- ── Stacked emails ──────────────────────────────────────────── --}}
    @foreach ($emails as $email)
        @php
            $from    = $email->from->first();
            $toList  = $email->participants->where('role', EmailParticipantRole::TO->value);
            $ccList  = $email->participants->where('role', EmailParticipantRole::CC->value);
            $aiLabel = $email->labels->firstWhere('source', 'ai');

            $senderName = $from?->name ?: $from?->email_address ?: '?';
            $initials   = collect(explode(' ', trim($senderName)))
                ->filter()->take(2)
                ->map(fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)))
                ->implode('');

            $canViewSubject = $authUser->can('viewSubject', $email);
            $canViewBody    = $authUser->can('viewBody', $email);
            $isOutbound     = $email->direction === EmailDirection::OUTBOUND;

            $safeHtml = $canViewBody && $email->body?->body_html
                ? preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $email->body->body_html)
                : null;
        @endphp

        <div class="{{ !$loop->last ? 'border-b border-gray-100 dark:border-gray-800' : '' }}">

            {{-- ── Email header ─────────────────────────────────────── --}}
            <div class="flex items-start gap-3 px-6 py-4">

                {{-- Avatar --}}
                <div @class([
                    'mt-0.5 flex h-9 w-9 shrink-0 select-none items-center justify-center rounded-full text-sm font-semibold ring-2 ring-white dark:ring-gray-900',
                    'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300' => $isOutbound,
                    'bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300' => !$isOutbound,
                ])>
                    {{ $initials ?: '?' }}
                </div>

                {{-- Sender info --}}
                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $senderName }}</span>
                        @if ($from?->email_address)
                            <span class="text-xs text-gray-400 dark:text-gray-500">&lt;{{ $from->email_address }}&gt;</span>
                        @endif
                    </div>

                    @if ($toList->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-x-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>to</span>
                            @foreach ($toList->take(3) as $r)
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $r->name ?: $r->email_address }}</span>{{ !$loop->last ? ',' : '' }}
                            @endforeach
                            @if ($toList->count() > 3)
                                <span>+{{ $toList->count() - 3 }} more</span>
                            @endif
                        </div>
                    @endif

                    @if ($ccList->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-x-1 text-xs text-gray-500 dark:text-gray-400">
                            <span>cc</span>
                            @foreach ($ccList->take(3) as $r)
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $r->name ?: $r->email_address }}</span>{{ !$loop->last ? ',' : '' }}
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Right: date + direction badge + reply actions --}}
                <div class="flex shrink-0 flex-col items-end gap-2">
                    <time class="whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">
                        {{ $email->sent_at?->format('M j, Y · g:i A') }}
                    </time>

                    <div class="flex items-center gap-1.5">
                        {{-- Direction badge --}}
                        <span @class([
                            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                            'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-400/10 dark:text-sky-400 dark:ring-sky-400/20' => !$isOutbound,
                            'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-400/10 dark:text-violet-400 dark:ring-violet-400/20' => $isOutbound,
                        ])>
                            {{ $email->direction->getLabel() }}
                        </span>

                        @if ($email->has_attachments)
                            <x-heroicon-o-paper-clip class="h-3.5 w-3.5 text-gray-400" />
                        @endif
                    </div>

                    {{-- Reply actions (only when viewer can reply) --}}
                    @if ($canViewBody)
                        <div x-data="{ moreOpen: false }" class="flex items-center gap-1">
                            <button
                                type="button"
                                x-on:click="$dispatch('reply-email', { emailId: '{{ $email->id }}', mode: 'reply' })"
                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-2.5 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                            >
                                <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" />
                                Reply
                            </button>

                            <div class="relative">
                                <button
                                    type="button"
                                    @click.stop="moreOpen = !moreOpen"
                                    class="inline-flex items-center gap-0.5 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                                    :class="{ 'bg-gray-50 dark:bg-gray-700': moreOpen }"
                                >
                                    More
                                    <x-heroicon-o-chevron-down class="h-3 w-3 transition-transform duration-100" ::class="moreOpen ? 'rotate-180' : ''" />
                                </button>

                                <div
                                    x-show="moreOpen"
                                    @click.outside="moreOpen = false"
                                    x-cloak
                                    class="absolute right-0 top-8 z-50 min-w-[9rem] rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg py-1"
                                >
                                    <button
                                        type="button"
                                        @click.stop="moreOpen = false; $dispatch('reply-email', { emailId: '{{ $email->id }}', mode: 'reply_all' })"
                                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        <x-heroicon-o-arrow-uturn-left class="h-4 w-4 shrink-0 scale-x-[-1] text-gray-400" />
                                        Reply All
                                    </button>
                                    <button
                                        type="button"
                                        @click.stop="moreOpen = false; $dispatch('reply-email', { emailId: '{{ $email->id }}', mode: 'forward' })"
                                        class="flex w-full items-center gap-2.5 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        <x-heroicon-o-arrow-right class="h-4 w-4 shrink-0 text-gray-400" />
                                        Forward
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Body ──────────────────────────────────────────────── --}}
            @if ($canViewBody)
                <div class="bg-gray-50 dark:bg-gray-900/30 px-6 pb-5">
                    <div class="rounded-xl border border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-950 px-7 py-5 shadow-xs">
                        @if ($safeHtml)
                            <iframe
                                srcdoc="{{ $safeHtml }}"
                                sandbox="allow-same-origin allow-popups"
                                class="w-full rounded-lg border-0"
                                style="min-height: 150px"
                                onload="this.style.height = this.contentDocument.body.scrollHeight + 'px'"
                            ></iframe>
                        @elseif ($email->body?->body_text)
                            <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $email->body->body_text }}</pre>
                        @else
                            <p class="text-sm italic text-gray-400">(no message body)</p>
                        @endif
                    </div>
                </div>

                {{-- Attachments --}}
                @if ($email->has_attachments && $email->attachments->isNotEmpty())
                    <div class="border-t border-gray-100 dark:border-gray-800 px-6 py-4">
                        <p class="mb-2 text-[10px] font-semibold uppercase tracking-widest text-gray-400">
                            Attachments <span class="font-normal normal-case tracking-normal">({{ $email->attachments->count() }})</span>
                        </p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($email->attachments as $attachment)
                                @php
                                    $downloadUrl = filled($attachment->provider_attachment_id)
                                        ? route('email-attachments.download', $attachment->getKey())
                                        : null;
                                    $bytes = $attachment->size ?? 0;
                                    $size  = match (true) {
                                        $bytes < 1_024     => $bytes.' B',
                                        $bytes < 1_048_576 => round($bytes / 1_024, 1).' KB',
                                        default            => round($bytes / 1_048_576, 1).' MB',
                                    };
                                @endphp
                                @if ($downloadUrl)
                                    <a href="{{ $downloadUrl }}" download class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 px-3 py-2.5 transition-colors hover:bg-gray-100 dark:hover:bg-gray-800 group">
                                @else
                                    <div class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 px-3 py-2.5">
                                @endif
                                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-gray-900 shadow-xs ring-1 ring-gray-200 dark:ring-gray-700">
                                            @if ($downloadUrl)
                                                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 text-primary-500 group-hover:text-primary-600" />
                                            @else
                                                <x-heroicon-o-paper-clip class="h-3.5 w-3.5 text-gray-400" />
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-medium {{ $downloadUrl ? 'text-primary-700 dark:text-primary-300' : 'text-gray-700 dark:text-gray-200' }}">
                                                {{ $attachment->filename ?? 'Unnamed file' }}
                                            </p>
                                            <p class="text-xs text-gray-400">{{ $size }}</p>
                                        </div>
                                @if ($downloadUrl)
                                    </a>
                                @else
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

            @else
                {{-- Privacy gate --}}
                <div class="px-6 pb-5">
                    <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 px-6 py-8 text-center">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-lock-closed class="h-5 w-5 text-gray-400" />
                        </div>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $canViewSubject ? 'Email body is restricted' : 'This email is private' }}
                        </p>
                        @if ($authUser->can('requestAccess', $email))
                            <p class="text-xs text-gray-400">Use <span class="font-semibold text-gray-600 dark:text-gray-300">Request Access</span> from the email list to ask for access.</p>
                        @endif
                    </div>
                </div>
            @endif

        </div>
    @endforeach

</div>
