@props(['record'])

@php
    use Relaticle\EmailIntegration\Enums\EmailDirection;
    use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
    use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

    $authUser = auth()->user();

    $from    = $record->fromParticipant();
    $toList  = $record->toParticipants();
    $ccList  = $record->ccParticipants();
    $aiLabel = $record->aiLabel();

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

    $recipientChipClass = 'inline-flex cursor-pointer items-center rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-300 ring-1 ring-inset ring-gray-200 dark:ring-gray-700 transition-colors hover:bg-gray-200 dark:hover:bg-gray-700';

    // Hoisted so the reader knows up front whether there is a frame to wait for: a
    // text-only body or a privacy gate has nothing to load and must not sit behind
    // a spinner.
    $inlineAttachments = $record->attachments->where('is_inline', true);
    $downloadAttachments = $record->attachments->where('is_inline', false);
    $safeHtml = $canViewBody ? EmailHtmlSanitizer::sanitize($record->body?->body_html, $inlineAttachments) : null;
@endphp

{{-- `ready` lives here so the message frame can flip it while the loading state sits
     with the frame itself — the header and actions are ready immediately and should
     not be held back behind it. --}}
<div
    x-data="{ ready: @js($safeHtml === null) }"
    class="flex min-h-0 flex-1 flex-col"
>

    {{-- ── Internal email banner ──────────────────────────────────────────── --}}
    @if ($record->is_internal && $isOwner)
        <div class="flex shrink-0 items-center gap-2.5 border-b border-blue-100 dark:border-blue-900/40 bg-blue-50 dark:bg-blue-950/30 px-6 py-2.5 text-sm text-blue-700 dark:text-blue-300">
            <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0" />
            <span class="font-medium">Internal email</span>
            <span class="text-blue-400">—</span>
            <span class="text-blue-600 dark:text-blue-400">visible only to workspace members and hidden from external views.</span>
        </div>
    @endif

    {{-- ── Header ──────────────────────────────────────────────────────────
         Two quiet rows — subject, then who and when — instead of one block that
         crams sender, address, date, badges and every recipient together. The
         recipients collapse behind a disclosure; they are reference, not headline. --}}

    {{-- Subject, with the record-level actions kept out of the reading path --}}
    <div class="flex shrink-0 items-start gap-3 border-b border-gray-100 dark:border-gray-800 px-4 py-3 pr-12 sm:px-6 sm:pr-14">
        {{-- Back to the list on narrow viewports, where the two panes alternate --}}
        <button
            wire:click="$set('selectedEmailId', null)"
            type="button"
            aria-label="{{ __('filament/pages/email-inbox.back_to_list') }}"
            class="-ml-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 lg:hidden"
        >
            <x-heroicon-o-arrow-left class="h-5 w-5" />
        </button>

        @if ($canViewSubject)
            {{-- A subject is the one thing worth reading in full: it wraps rather than
                 truncating, since the ellipsis usually hides the part that matters. --}}
            <h2 class="min-w-0 flex-1 text-base font-semibold leading-snug text-gray-900 dark:text-white break-words">
                {{ $record->subject ?: '(no subject)' }}
            </h2>
        @else
            <p class="min-w-0 flex-1 text-sm italic text-gray-400 dark:text-gray-500">(subject hidden)</p>
        @endif

        {{-- gap-2, not gap-1: adjacent hit targets want ~8px between them so they do
             not read as one blob and are not mis-tapped. --}}
        <div class="flex shrink-0 items-center gap-2 pt-0.5">
            <x-emails.detail-action-bar :email="$record" />
        </div>
    </div>

    {{-- Sender, recipients and the reply actions --}}
    <div
        x-data="{
            recipientsOpen: false,

            /**
             * Bring a freshly opened draft into view. Called twice on a delay because
             * the message iframe is still settling to its measured height when the
             * draft mounts, and that resize pushes the draft further down.
             *
             * The region opts into smooth scrolling in CSS, so these plain assignments
             * ease. Some environments discard a smooth programmatic scroll outright
             * rather than merely skipping the animation — hence the final check, which
             * forces the position only if the draft was never actually reached.
             */
            scrollToDraft() {
                const region = () => document.querySelector('[data-email-scroll-region]')

                ;[150, 500].forEach((delay) => setTimeout(() => {
                    const el = region()

                    if (el) el.scrollTop = el.scrollHeight
                }, delay))

                setTimeout(() => {
                    const el = region()

                    if (! el) return

                    const target = el.scrollHeight - el.clientHeight

                    if (el.scrollTop >= target - 4) return

                    el.style.scrollBehavior = 'auto'
                    el.scrollTop = target
                    el.style.scrollBehavior = ''
                }, 1200)
            },
        }"
        {{-- A draft that comes back with its email was not opened by a click here, so
             the composer says when it has docked and the reader scrolls to it. --}}
        x-on:composer:opened-inline.window="scrollToDraft()"
        class="shrink-0 border-b border-gray-100 dark:border-gray-800 px-4 py-3 sm:px-6"
    >
        <div class="flex items-start gap-3">
            <div class="flex h-8 w-8 aspect-square shrink-0 select-none items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/40 text-xs font-semibold text-primary-700 dark:text-primary-300">
                {{ $initials ?: '?' }}
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $from?->name ?: $from?->email_address ?: '(unknown sender)' }}
                    </span>
                    @if ($aiLabel)
                        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-medium {{ $aiLabelColor }}">
                            {{ $aiLabel->label }}
                        </span>
                    @endif
                </div>

                @if ($toList->isNotEmpty() || $ccList->isNotEmpty())
                    <button
                        type="button"
                        x-on:click="recipientsOpen = ! recipientsOpen"
                        class="mt-0.5 flex max-w-full items-center gap-1 text-xs text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                    >
                        <span class="truncate">
                            {{ __('filament/pages/email-inbox.recipients.to') }}
                            {{ $toList->first()?->email_address ?: $toList->first()?->name }}
                            @php $otherRecipients = $toList->count() + $ccList->count() - 1; @endphp
                            @if ($otherRecipients > 0)
                                {{ trans_choice('filament/pages/email-inbox.recipients.more', $otherRecipients, ['count' => $otherRecipients]) }}
                            @endif
                        </span>
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 transition-transform" x-bind:class="recipientsOpen && 'rotate-180'" />
                    </button>

                    <div x-show="recipientsOpen" x-collapse x-cloak class="mt-2 space-y-1">
                        @foreach ([__('filament/pages/email-inbox.recipients.to') => $toList, __('filament/pages/email-inbox.recipients.cc') => $ccList] as $groupLabel => $group)
                            @if ($group->isNotEmpty())
                                <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                                    <span class="w-6 shrink-0 text-xs text-gray-400 dark:text-gray-500">{{ $groupLabel }}</span>
                                    @foreach ($group as $recipient)
                                        <span class="{{ $recipientChipClass }}" title="{{ $recipient->name }}">
                                            {{ $recipient->email_address ?: $recipient->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($canViewBody)
                    @foreach ([
                        'reply'     => ['ri-reply-line', __('filament/pages/email-inbox.reply_forward.modal_headings.reply')],
                        'reply_all' => ['ri-reply-all-line', __('filament/pages/email-inbox.reply_forward.modal_headings.reply_all')],
                        'forward'   => ['ri-share-forward-line', __('filament/pages/email-inbox.reply_forward.modal_headings.forward')],
                    ] as $replyMode => [$icon, $label])
                        {{-- The scroll is driven from here, not from the composer's own
                             x-init: the composer is a nested Livewire component, and
                             Alpine does not re-run x-init when Livewire morphs it open. --}}
                        <button
                            x-on:click="
                                $dispatch('composer:reply', { emailId: '{{ $record->id }}', mode: '{{ $replyMode }}' });
                                scrollToDraft()
                            "
                            type="button"
                            aria-label="{{ $label }}"
                            x-tooltip="{ content: @js($label), theme: $store.theme }"
                            class="flex h-7 w-7 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300"
                        >
                            <x-filament::icon :icon="$icon" class="h-4 w-4" />
                        </button>
                    @endforeach
                @endif

                @if ($record->sent_at)
                    <time class="ml-2 hidden whitespace-nowrap text-xs text-gray-400 dark:text-gray-500 sm:block">
                        {{ $record->sent_at->format('M j, Y · g:i A') }}
                    </time>
                @endif
            </div>
        </div>
    </div>

    {{-- Message and draft share one scroll region: replying appends the draft under
         the message and scrolls down to it, and scrolling back up shows the original
         again. The body iframe therefore needs a height of its own — see below. --}}
    {{-- `motion-safe:scroll-smooth` is what animates the jump to a new draft: the
         scroll is a plain scrollTop assignment, which the browser eases when the
         element opts in — and the variant drops it for reduced-motion users. --}}
    <div class="flex min-h-0 flex-1 flex-col overflow-y-auto motion-safe:scroll-smooth" data-email-scroll-region>

    {{-- ── Attachments ─────────────────────────────────────────────────────── --}}
    @if ($canViewBody && $downloadAttachments->isNotEmpty())
        <div class="flex max-h-28 shrink-0 flex-wrap items-center gap-2 overflow-y-auto border-b border-gray-100 dark:border-gray-800 px-4 py-2.5 sm:px-6">
            <x-heroicon-o-paper-clip class="h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />

            @foreach ($downloadAttachments as $attachment)
                @php
                    $downloadUrl = filled($attachment->provider_attachment_id)
                        ? route('email-attachments.download', $attachment->getKey())
                        : null;
                @endphp

                <x-emails.attachment-card
                    :filename="$attachment->filename ?? __('filament/pages/email-inbox.reader.attachments.unnamed')"
                    :size="$downloadUrl ? ($attachment->size ?? 0) : null"
                    :placeholder="__('filament/pages/email-inbox.reader.attachments.processing')"
                    :class="$downloadUrl ? '' : 'opacity-60'"
                >
                    @if ($downloadUrl)
                        <a
                            href="{{ $downloadUrl }}"
                            download
                            aria-label="{{ __('filament/emails/composer.actions.download_attachment') }}"
                            class="shrink-0 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200"
                        >
                            <x-heroicon-m-arrow-down-tray class="h-4 w-4" />
                        </a>
                    @endif
                </x-emails.attachment-card>
            @endforeach
        </div>
    @endif

    {{-- ── Body ────────────────────────────────────────────────────────────── --}}
    @if ($canViewBody)
        @if ($safeHtml !== null)
            {{-- The frame is sized to its content, so it never scrolls itself: the
                 region around it is the only scroller, and a short email leaves no dead
                 space before the draft.

                 `allow-same-origin` is what makes that measurement possible — it lets
                 THIS page read into the frame. It does not let anything run in there;
                 that is `allow-scripts`, which stays withheld, so untrusted email HTML
                 still cannot execute. The pair is only dangerous together, because a
                 script inside a same-origin frame can strip its own sandbox attribute.
                 Never add `allow-scripts` here without removing `allow-same-origin`. --}}
            <div
                x-data="{
                    ready: false,
                    fit() {
                        const doc = $refs.body?.contentDocument

                        if (! doc?.documentElement) return

                        $refs.body.style.height = '1px'
                        $refs.body.style.height = Math.max(doc.documentElement.scrollHeight, 160) + 'px'
                    },
                    init() {
                        this.fit()

                        $refs.body.addEventListener('load', () => {
                            this.fit()
                            this.ready = true

                            // Images and webfonts arrive after load and change the height.
                            const doc = $refs.body.contentDocument

                            if (doc?.body) new ResizeObserver(() => this.fit()).observe(doc.body)
                        })

                        // A frame that never fires load must not leave a spinner forever.
                        setTimeout(() => { this.ready = true }, 5000)
                    },
                }"
                x-bind:class="ready ? 'shrink-0' : 'flex min-h-0 flex-1 flex-col'"
                class="bg-gray-50 dark:bg-gray-950"
            >
                <div
                    x-bind:class="ready ? '' : 'min-h-0 flex-1'"
                    class="relative w-full overflow-hidden border-y border-gray-100 bg-white dark:border-gray-800 dark:bg-neutral-950"
                >
                    <div
                        x-show="! ready"
                        x-cloak
                        class="absolute inset-0 z-10 flex items-center justify-center bg-white dark:bg-neutral-950"
                    >
                        <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                    </div>

                    <iframe
                        x-ref="body"
                        x-bind:class="ready ? 'opacity-100' : 'opacity-0'"
                        srcdoc="{{ $safeHtml }}"
                        sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                        referrerpolicy="no-referrer"
                        scrolling="no"
                        class="block w-full border-0 bg-white transition-opacity duration-150 [color-scheme:light] dark:bg-neutral-950 dark:[color-scheme:dark]"
                        {{-- A placeholder tall enough to centre the spinner in; replaced by
                             the measured content height the moment the frame loads. --}}
                        style="height: 24rem"
                    ></iframe>
                </div>
            </div>
        @else
            <div class="shrink-0 px-6 py-5">
                @if ($record->body?->body_text)
                    <pre class="whitespace-pre-wrap font-sans text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $record->body->body_text }}</pre>
                @else
                    <p class="text-sm italic text-gray-400 dark:text-gray-500">(no message body)</p>
                @endif
            </div>
        @endif

    {{-- ── Privacy gate ────────────────────────────────────────────────────── --}}
    @else
        <div class="shrink-0 px-6 py-8">
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

</div>
