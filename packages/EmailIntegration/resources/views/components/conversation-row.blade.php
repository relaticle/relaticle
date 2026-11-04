@props(['row'])

@php
    use Filament\Support\Facades\FilamentTimezone;

    $selectable = $row->access->showsBody();
    $sentAt = $row->lastMessageAt->setTimezone(FilamentTimezone::get());
    $timestamp = match (true) {
        $sentAt->isToday() => $sentAt->format('g:i A'),
        $sentAt->isYesterday() => __('filament/pages/email-inbox.list_row.timestamp_yesterday', ['time' => $sentAt->format('g:i A')]),
        $sentAt->diffInDays() < 7 => $sentAt->format('D'),
        $sentAt->isCurrentYear() => $sentAt->format('M j'),
        default => $sentAt->format('M j, Y'),
    };
    $subject = match (true) {
        ! $row->access->showsSubject() => __('filament/pages/email-inbox.subject.hidden'),
        filled($row->subject) => html_entity_decode($row->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        default => __('filament/pages/email-inbox.subject.none'),
    };
    $rowTag = $selectable ? 'button' : 'div';
@endphp

<{{ $rowTag }}
    data-conversation="{{ $row->conversationKey }}"
    data-message-count="{{ $row->messageCount }}"
    @if ($selectable)
        type="button"
        wire:click="selectEmail('{{ $row->viewerCopyEmailId }}')"
    @endif
    {{ $attributes->class([
        'flex w-full items-center gap-3 px-4 py-3 text-left transition-colors sm:px-6',
        'cursor-pointer hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500 dark:hover:bg-white/5' => $selectable,
        'cursor-default' => ! $selectable,
    ]) }}
>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ implode(', ', $row->senderNames) }}</span>
        <span @class([
            'block truncate text-sm leading-5',
            'font-medium text-gray-800 dark:text-gray-200' => $row->access->showsSubject(),
            'text-gray-400 dark:text-gray-500' => ! $row->access->showsSubject(),
        ])>{{ $subject }}</span>
    </span>

    <time datetime="{{ $row->lastMessageAt->toIso8601String() }}" class="shrink-0 whitespace-nowrap text-xs text-gray-400 dark:text-gray-500">
        {{ $timestamp }}
    </time>
</{{ $rowTag }}>
