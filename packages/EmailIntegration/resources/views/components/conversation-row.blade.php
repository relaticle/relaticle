@props(['row', 'selectable' => false])

@php
    use Filament\Support\Facades\FilamentTimezone;

    $opens = $selectable;
    $sentAt = $row->lastMessageAt->setTimezone(FilamentTimezone::get());
    $timestamp = match (true) {
        $sentAt->isToday() => $sentAt->format('g:i A'),
        $sentAt->isYesterday() => __('filament/pages/email-inbox.list_row.timestamp_yesterday', ['time' => $sentAt->format('g:i A')]),
        $sentAt->diffInDays() < 7 => $sentAt->format('D'),
        $sentAt->isCurrentYear() => $sentAt->format('M j'),
        default => $sentAt->format('M j, Y'),
    };
    $decode = fn (?string $text): string => html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $subject = match (true) {
        ! $row->access->showsSubject() => __('filament/pages/email-inbox.subject.hidden'),
        filled($row->subject) => $decode($row->subject),
        default => __('filament/pages/email-inbox.subject.none'),
    };
    $snippet = trim($decode($row->snippet));
    $ownerInitials = collect(explode(' ', trim((string) $row->ownerName)))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
@endphp

<div
    data-conversation="{{ $row->conversationKey }}" data-unread="{{ $row->isUnread ? 'true' : 'false' }}" data-message-count="{{ $row->messageCount }}"
    {{ $attributes->class([
        'relative flex w-full items-start gap-2 px-4 py-3 transition-colors sm:px-6',
        'hover:bg-gray-50 dark:hover:bg-white/5' => $opens,
    ]) }}
>
    @if ($opens)
        <button
            type="button"
            wire:click="selectEmail('{{ $row->viewerCopyEmailId }}')"
            wire:loading.attr="disabled"
            wire:target="selectEmail('{{ $row->viewerCopyEmailId }}')"
            aria-label="{{ $subject }}"
            class="absolute inset-0 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 data-[loading]:cursor-wait"
        ></button>
    @endif

    <span class="flex h-5 w-2 shrink-0 items-center justify-center">
        @if ($row->isUnread)
            <span role="img" aria-label="{{ __('filament/pages/email-inbox.conversation_row.unread') }}" class="size-2 rounded-full bg-primary-500"></span>
        @endif
    </span>

    <div class="pointer-events-none relative min-w-0 flex-1">
        <div class="flex items-center gap-2">
            <span class="min-w-0 flex-1 truncate text-sm font-medium leading-5 text-gray-800 dark:text-gray-200">
                {{ implode(', ', $row->senderNames) }}
                @if ($row->messageCount > 1)
                    <span class="font-normal text-gray-400 dark:text-gray-500">({{ $row->messageCount }})</span>
                @endif
            </span>

            @if ($row->ownerName !== null)
                <span
                    title="{{ $row->ownerName }}"
                    class="pointer-events-auto flex size-5 shrink-0 select-none items-center justify-center rounded-full bg-gray-100 text-[9px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-white/10 dark:text-gray-300 dark:ring-white/10"
                >{{ $ownerInitials ?: '?' }}</span>
            @endif

            @if ($row->hasAttachments)
                <x-ri-attachment-2 class="size-3.5 shrink-0 text-gray-400 dark:text-gray-500" aria-hidden="true" />
            @endif

            <time
                datetime="{{ $row->lastMessageAt->toIso8601String() }}"
                title="{{ $sentAt->format('M j, Y · g:i A') }}"
                class="shrink-0 whitespace-nowrap text-xs tabular-nums text-gray-400 dark:text-gray-500"
            >{{ $timestamp }}</time>
        </div>

        <div class="mt-0.5 flex items-center gap-3">
            <span class="min-w-0 flex-1 truncate text-sm leading-5">
                <span @class([
                    'text-gray-400 dark:text-gray-500' => ! $row->access->showsSubject(),
                    'font-semibold text-gray-900 dark:text-white' => $row->access->showsSubject() && $row->isUnread,
                    'text-gray-700 dark:text-gray-300' => $row->access->showsSubject() && ! $row->isUnread,
                ])>{{ $subject }}</span>
                @if ($snippet !== '')
                    <span class="hidden text-gray-500 sm:inline dark:text-gray-400">{{ $snippet }}</span>
                @endif
            </span>

            <span class="flex shrink-0 items-center gap-1">
                @forelse ($row->records as $index => $record)
                    @if ($index < 2)
                        <x-email-integration::record-chip
                            :record="$record"
                            :class="\Illuminate\Support\Arr::toCssClasses(['pointer-events-auto', 'inline-flex' => $index === 0, 'hidden sm:inline-flex' => $index === 1])"
                        />
                    @endif
                @empty
                    <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-medium leading-4 text-gray-400 ring-1 ring-inset ring-gray-200 dark:text-gray-500 dark:ring-white/10">
                        {{ __('filament/pages/email-inbox.conversation_row.unlinked') }}
                    </span>
                @endforelse

                @if (count($row->records) > 1)
                    <span class="text-[11px] font-medium text-gray-400 sm:hidden dark:text-gray-500">
                        {{ __('filament/pages/email-inbox.conversation_row.more_records', ['count' => count($row->records) - 1]) }}
                    </span>
                @endif
                @if (count($row->records) > 2)
                    <span class="hidden text-[11px] font-medium text-gray-400 sm:inline dark:text-gray-500">
                        {{ __('filament/pages/email-inbox.conversation_row.more_records', ['count' => count($row->records) - 2]) }}
                    </span>
                @endif
            </span>
        </div>

        @unless ($row->access->showsBody())
            <span class="pointer-events-auto">
                <x-email-integration::request-access-list-pill
                    :email-id="$row->viewerCopyEmailId"
                    :owner-name="$row->ownerName ?? __('filament/pages/email-inbox.pending_access.unknown_user')"
                    :requested="$row->viewerHasPendingRequest"
                />
            </span>
        @endunless
    </div>
</div>
