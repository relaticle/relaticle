@props(['row', 'selectable' => false, 'current' => false, 'triage' => false])

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
    data-conversation="{{ $row->conversationKey }}" data-unread="{{ $row->isUnread ? 'true' : 'false' }}" data-message-count="{{ $row->messageCount }}" data-done="{{ $row->isDone ? 'true' : 'false' }}"
    @if ($current) aria-current="true" @endif
    @if ($triage)
        x-bind:hidden="isHidden(@js($row->conversationKey))"
        x-bind:data-unread="flag(@js($row->conversationKey), 'unread', @js($row->isUnread))"
        x-bind:data-done="flag(@js($row->conversationKey), 'done', @js($row->isDone))"
    @endif
    {{ $attributes->class([
        'group/row relative flex w-full items-start gap-2 px-4 py-3 transition-colors sm:px-6',
        'hover:bg-gray-50 dark:hover:bg-white/5' => $opens,
        'bg-gray-50 before:absolute before:inset-y-0 before:start-0 before:w-0.5 before:bg-primary-500 dark:bg-white/5' => $current,
    ]) }}
>
    @if ($opens)
        <button
            type="button"
            x-on:click="
                const open = () => $wire.selectEmail('{{ $row->viewerCopyEmailId }}')
                window.dispatchEvent(new CustomEvent('email-reader-leave', { cancelable: true, detail: { then: open } })) && open()
            "
            wire:loading.attr="disabled"
            wire:target="selectEmail('{{ $row->viewerCopyEmailId }}')"
            aria-label="{{ $subject }}"
            class="absolute inset-0 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 data-[loading]:cursor-wait"
        ></button>
    @endif

    <span class="flex h-5 w-2 shrink-0 items-center justify-center">
        <span role="img" aria-label="{{ __('filament/pages/email-inbox.conversation_row.unread') }}" class="hidden size-2 rounded-full bg-primary-500 group-data-[unread=true]/row:block"></span>
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
                    'text-gray-700 group-data-[unread=true]/row:font-semibold group-data-[unread=true]/row:text-gray-900 dark:text-gray-300 dark:group-data-[unread=true]/row:text-white' => $row->access->showsSubject(),
                ])>{{ $subject }}</span>
                @if ($snippet !== '')
                    <span class="hidden text-gray-500 sm:inline dark:text-gray-400">{{ $snippet }}</span>
                @endif
            </span>

            <span class="flex shrink-0 items-center gap-1">
                <span class="hidden items-center gap-0.5 rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium leading-4 text-gray-600 group-data-[done=true]/row:inline-flex dark:bg-white/10 dark:text-gray-300">
                    <x-filament::icon icon="ri-check-line" class="size-3" aria-hidden="true" />
                    {{ __('filament/pages/email-inbox.triage.done_badge') }}
                </span>

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
    @if ($triage)
        @php
            $buttonClass = 'items-center justify-center rounded-md p-1.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-100';
            $actions = [
                ['verb' => 'markDone', 'icon' => 'ri-check-line', 'label' => 'done', 'shown' => 'inline-flex group-data-[done=true]/row:hidden', 'advance' => true],
                ['verb' => 'reopen', 'icon' => 'ri-inbox-unarchive-line', 'label' => 'reopen', 'shown' => 'hidden group-data-[done=true]/row:inline-flex', 'advance' => false],
                ['verb' => 'markRead', 'icon' => 'ri-mail-open-line', 'label' => 'mark_read', 'shown' => 'hidden group-data-[unread=true]/row:inline-flex', 'advance' => false],
                ['verb' => 'markUnread', 'icon' => 'ri-mail-unread-line', 'label' => 'mark_unread', 'shown' => 'inline-flex group-data-[unread=true]/row:hidden', 'advance' => false],
            ];
        @endphp

        <div
            data-triage-actions
            class="absolute end-3 top-1/2 z-10 hidden -translate-y-1/2 items-center gap-0.5 rounded-lg bg-white p-0.5 shadow-sm ring-1 ring-gray-950/5 group-focus-within/row:flex group-hover/row:flex dark:bg-gray-900 dark:ring-white/10"
        >
            @foreach ($actions as $action)
                <button
                    type="button"
                    data-triage-action="{{ $action['verb'] }}"
                    x-on:click.stop="act(@js($action['verb']), [@js($row->conversationKey)], { advance: @js($action['advance']) })"
                    title="{{ __('filament/pages/email-inbox.triage.actions.'.$action['label']) }}"
                    aria-label="{{ __('filament/pages/email-inbox.triage.actions.'.$action['label']) }}"
                    class="{{ $action['shown'] }} {{ $buttonClass }}"
                >
                    <x-filament::icon :icon="$action['icon']" class="size-4" />
                </button>
            @endforeach
        </div>
    @endif
</div>
