<span class="inline-flex min-w-0 flex-wrap items-center gap-2">
    {{ __('filament/record-page.merge.heading') }}
    <span class="inline-flex max-w-64 min-w-0 items-center gap-1.5 rounded-lg border border-gray-950/10 bg-white py-0.5 pe-2 ps-1 text-sm font-medium text-gray-950 dark:border-white/15 dark:bg-white/5 dark:text-white" title="{{ $name }}">
        @include('filament.app.merge-record.mark', ['logo' => $logo, 'icon' => $icon, 'size' => 'size-5 rounded-md', 'iconSize' => 'size-3.5'])
        <span class="truncate">{{ $name }}</span>
    </span>
</span>
