@php
    $event = $entry->event;
    $causerName = $entry->causer?->name ?? null;

    $palette = match ($event) {
        'email_sent' => [
            'icon' => 'heroicon-s-envelope',
            'marker' => 'bg-blue-500 ring-blue-100 dark:ring-blue-500/20',
            'label' => 'Email sent',
            'badge' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/30',
        ],
        'email_received' => [
            'icon' => 'heroicon-s-envelope',
            'marker' => 'bg-blue-500 ring-blue-100 dark:ring-blue-500/20',
            'label' => 'Email received',
            'badge' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/30',
        ],
        'note_created' => [
            'icon' => 'heroicon-s-document-text',
            'marker' => 'bg-amber-500 ring-amber-100 dark:ring-amber-500/20',
            'label' => 'Note created',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        ],
        'task_created' => [
            'icon' => 'heroicon-s-check',
            'marker' => 'bg-emerald-500 ring-emerald-100 dark:ring-emerald-500/20',
            'label' => 'Task created',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        ],
        'created' => [
            'icon' => 'heroicon-s-check',
            'marker' => 'bg-emerald-500 ring-emerald-100 dark:ring-emerald-500/20',
            'label' => 'Created',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        ],
        'updated' => [
            'icon' => 'heroicon-s-pencil-square',
            'marker' => 'bg-violet-500 ring-violet-100 dark:ring-violet-500/20',
            'label' => 'Updated',
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30',
        ],
        'deleted' => [
            'icon' => 'heroicon-s-trash',
            'marker' => 'bg-rose-500 ring-rose-100 dark:ring-rose-500/20',
            'label' => 'Deleted',
            'badge' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30',
        ],
        'restored' => [
            'icon' => 'heroicon-s-arrow-uturn-left',
            'marker' => 'bg-amber-500 ring-amber-100 dark:ring-amber-500/20',
            'label' => 'Restored',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        ],
        default => [
            'icon' => 'heroicon-s-check',
            'marker' => 'bg-gray-400 ring-gray-100 dark:ring-white/10',
            'label' => \Illuminate\Support\Str::headline($event),
            'badge' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10',
        ],
    };

    $fallbackTitle = \Illuminate\Support\Str::headline($event);
    $title = $entry->title ?? $fallbackTitle;
    $description = $entry->description
        ?? ($causerName
            ? sprintf('%s %s', $causerName, \Illuminate\Support\Str::lower($fallbackTitle))
            : $fallbackTitle);
@endphp

<div class="grid grid-cols-[108px_28px_1fr] items-start gap-x-4" data-type="{{ $entry->type }}" data-event="{{ $event }}">
    <div class="pt-3 text-right leading-tight">
        <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 tabular-nums">
            {{ $entry->occurredAt->format('d M') }}
        </div>
        <div class="mt-0.5 text-[11px] text-gray-500 dark:text-gray-400">
            {{ $entry->occurredAt->format('D \a\t g:i A Y') }}
        </div>
    </div>

    <div class="relative flex justify-center pt-3">
        <span class="z-10 flex h-6 w-6 items-center justify-center rounded-full ring-4 {{ $palette['marker'] }}">
            <x-filament::icon :icon="$palette['icon']" class="h-3.5 w-3.5 text-white" />
        </span>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $title }}
            </p>
            <span class="inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset {{ $palette['badge'] }}">
                {{ $palette['label'] }}
            </span>
        </div>
        @if ($description)
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ $description }}
            </p>
        @endif
    </div>
</div>
