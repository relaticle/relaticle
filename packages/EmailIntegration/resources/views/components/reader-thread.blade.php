@props(['thread', 'bodies', 'expanded', 'replyTarget', 'email'])

@php
    $authUser = auth()->user();
    $decode = fn (?string $text): string => html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
@endphp

<div class="flex flex-col divide-y divide-gray-100 dark:divide-white/5">
    @foreach ($thread as $message)
        @if ($bodies->has($message->getKey()))
            <div wire:key="thread-message-{{ $message->getKey() }}" data-thread-message="{{ $message->getKey() }}">
                <x-email-integration::email-view :record="$bodies->get($message->getKey())" threaded />
            </div>
        @elseif (in_array($message->getKey(), $expanded, true))
            <div wire:key="thread-message-{{ $message->getKey() }}" data-thread-message="{{ $message->getKey() }}">
                <x-email-integration::reader-gate :email="$message" />
            </div>
        @else
            @php
                $from = $message->fromParticipant();
                $snippet = $authUser->can('viewBody', $message) ? trim($decode($message->snippet)) : '';
                $sentAt = $message->sentAtForViewer();
            @endphp

            <button
                type="button"
                wire:key="thread-row-{{ $message->getKey() }}"
                wire:click="expandMessage('{{ $message->getKey() }}')"
                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition-colors hover:bg-gray-50 sm:px-6 dark:hover:bg-white/5"
            >
                <span class="w-36 shrink-0 truncate font-medium text-gray-800 dark:text-gray-200">
                    {{ $from?->name ?: $from?->email_address ?: __('filament/pages/email-inbox.reader.unknown_sender') }}
                </span>
                <span class="min-w-0 flex-1 truncate text-gray-500 dark:text-gray-400">{{ $snippet }}</span>
                @if ($sentAt !== null)
                    <time datetime="{{ $sentAt->toIso8601String() }}" class="shrink-0 whitespace-nowrap text-xs tabular-nums text-gray-400 dark:text-gray-500">
                        {{ $sentAt->format('M j, g:i A') }}
                    </time>
                @endif
            </button>
        @endif

        @if ($replyTarget?->is($message))
            @livewire('email-integration.composer', ['dock' => 'inline'], key('inline-reply-composer-'.$email->getKey()))
        @endif
    @endforeach
</div>
