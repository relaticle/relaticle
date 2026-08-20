@props(['record'])

{{-- A read-only rendering of the message a draft answers or forwards.

     Deliberately not `x-emails.email-view`: that component is the reader, and it
     carries the reply actions and the draft dock itself — rendering it inside the
     composer would nest a composer in a composer. This shows only who wrote what. --}}
@php
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
    use Relaticle\EmailIntegration\Support\EmailHtmlSanitizer;

    $authUser = auth()->user();
    $from = $record->participants->firstWhere('role', EmailParticipantRole::FROM);
    $senderName = $from?->name ?: $from?->email_address ?: '?';

    $initials = collect(explode(' ', trim($senderName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $safeHtml = $authUser->can('viewBody', $record)
        ? EmailHtmlSanitizer::sanitize($record->body?->body_html)
        : null;
@endphp

<div class="flex flex-col">

    <div class="flex shrink-0 items-start gap-3 px-4 py-3 sm:px-6">
        <div class="flex h-8 w-8 aspect-square shrink-0 select-none items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800 text-xs font-semibold text-gray-500 dark:text-gray-400">
            {{ $initials ?: '?' }}
        </div>

        <div class="min-w-0 flex-1">
            @if ($authUser->can('viewSubject', $record))
                <p class="text-sm font-medium text-gray-900 dark:text-white break-words">
                    {{ $record->subject ?: '(no subject)' }}
                </p>
            @else
                <p class="text-sm italic text-gray-400 dark:text-gray-500">(subject hidden)</p>
            @endif

            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">
                {{ $senderName }}
                @if ($record->sent_at)
                    · {{ $record->sent_at->format('M j, Y · g:i A') }}
                @endif
            </p>
        </div>
    </div>

    @if ($safeHtml !== null)
        {{-- Same sizing and sandbox as the reader: measured from the parent, with
             `allow-scripts` withheld so the quoted HTML still cannot execute. --}}
        <div
            x-data="{
                fit() {
                    const doc = $refs.quoted?.contentDocument

                    if (! doc?.documentElement) return

                    $refs.quoted.style.height = doc.documentElement.scrollHeight + 'px'
                },
                init() {
                    this.fit()

                    $refs.quoted.addEventListener('load', () => {
                        this.fit()

                        const doc = $refs.quoted.contentDocument

                        if (doc?.body) new ResizeObserver(() => this.fit()).observe(doc.body)
                    })
                },
            }"
            class="shrink-0 bg-white dark:bg-gray-950"
        >
            <iframe
                x-ref="quoted"
                srcdoc="{{ $safeHtml }}"
                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                referrerpolicy="no-referrer"
                scrolling="no"
                class="block w-full border-0 [color-scheme:light] dark:[color-scheme:dark]"
                style="height: 12rem"
            ></iframe>
        </div>
    @else
        <p class="px-4 pb-4 text-sm italic text-gray-400 dark:text-gray-500 sm:px-6">
            {{ __('filament/emails/composer.quoted.hidden') }}
        </p>
    @endif

</div>
