@php
    use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

    $toList = $record->participants->where('role', EmailParticipantRole::TO);
    $ccList = $record->participants->where('role', EmailParticipantRole::CC);
@endphp

@if ($toList->isNotEmpty() || $ccList->isNotEmpty())
    <div class="flex flex-wrap items-center gap-x-1 gap-y-1 text-xs">

        @if ($toList->isNotEmpty())
            <span class="font-medium text-gray-400 dark:text-gray-500">To:</span>
            @foreach ($toList as $recipient)
                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-gray-700 dark:text-gray-300">
                    {{ $recipient->name ?: $recipient->email_address }}
                </span>
            @endforeach
        @endif

        @if ($ccList->isNotEmpty())
            <span class="font-medium text-gray-400 dark:text-gray-500 ml-1">CC:</span>
            @foreach ($ccList as $recipient)
                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-800 px-2 py-0.5 text-gray-700 dark:text-gray-300">
                    {{ $recipient->name ?: $recipient->email_address }}
                </span>
            @endforeach
        @endif

    </div>
@endif
