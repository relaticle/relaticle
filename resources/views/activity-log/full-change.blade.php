@php
    /** @var array{old: string, new: string} $full */
@endphp

<div class="grid gap-2">
    @foreach (['old' => __('workspaces.activity.full_change.before'), 'new' => __('workspaces.activity.full_change.after')] as $side => $heading)
        <div>
            <p class="text-[11px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $heading }}</p>
            <p class="mt-1 max-h-64 overflow-y-auto whitespace-pre-line break-words rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 dark:border-white/10 dark:bg-white/[0.02] dark:text-gray-300">{{ $full[$side] }}</p>
        </div>
    @endforeach
</div>
