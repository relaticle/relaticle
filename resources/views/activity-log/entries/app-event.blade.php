@php
    $causerName = $entry->causer?->name ?? null;
    $fallbackTitle = $palette->label();
    $title = $entry->title ?? $fallbackTitle;
    $description = $entry->description
        ?? ($causerName
            ? sprintf('%s %s', $causerName, Str::lower($fallbackTitle))
            : $fallbackTitle);
    $badge = $palette->badge();
@endphp

<div
    class="grid grid-cols-[28px_1fr_auto] items-start gap-x-3 rounded-md px-2 py-2 transition hover:bg-gray-50/60 dark:hover:bg-white/[0.03]"
    data-type="{{ $entry->type }}"
    data-event="{{ $entry->event }}"
>
    <div class="flex justify-center pt-0.5">
        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-50 text-primary-600 ring-1 ring-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/20">
            <x-filament::icon :icon="$palette->icon()" class="h-3.5 w-3.5" />
        </span>
    </div>

    <div class="min-w-0">
        <p class="flex items-center gap-2 text-[13px] leading-5 text-gray-900 dark:text-gray-100">
            <span class="font-medium">{{ $title }}</span>
            @if ($badge !== null)
                <span @class([
                    'inline-flex shrink-0 items-center rounded px-1 py-0 text-[9px] font-medium uppercase leading-[14px] tracking-wide ring-1 ring-inset',
                    'bg-primary-50 text-primary-700 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-500/20' => $badge['tone'] === 'primary',
                    'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $badge['tone'] === 'success',
                ])>
                    {{ $badge['text'] }}
                </span>
            @endif
        </p>
        @if ($description)
            <p class="mt-0.5 text-[12px] text-gray-500 dark:text-gray-400">
                {{ $description }}
            </p>
        @endif
    </div>

    <div class="flex items-center pt-0.5">
        <time
            class="text-[11px] text-gray-500 dark:text-gray-400 tabular-nums"
            datetime="{{ $entry->occurredAt->toIso8601String() }}"
            title="{{ $entry->occurredAt->toDayDateTimeString() }}"
        >
            {{ $entry->occurredAt->diffForHumans(syntax: null, short: true) }}
        </time>
    </div>
</div>
