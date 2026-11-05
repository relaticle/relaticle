<div {{ $attributes->class('rounded-xl border border-gray-200 bg-gray-100 px-1.5 pb-1.5 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_24px_48px_-24px_rgba(0,0,0,0.18)] sm:rounded-2xl sm:px-2 sm:pb-2 dark:border-white/[0.1] dark:bg-gray-900 dark:shadow-[inset_0_1px_0_rgba(255,255,255,0.06),0_24px_48px_-24px_rgba(0,0,0,0.6)]') }}>
    <div class="flex h-8 items-center gap-1.5 px-1 sm:h-9 sm:px-1.5" aria-hidden="true">
        <span class="size-2.5 rounded-full bg-[#ff5c5a] sm:size-3"></span>
        <span class="size-2.5 rounded-full bg-[#f5b902] sm:size-3"></span>
        <span class="size-2.5 rounded-full bg-[#10c27b] sm:size-3"></span>
    </div>

    <div class="relative overflow-hidden rounded-md border border-gray-200 bg-white sm:rounded-lg dark:border-white/[0.06] dark:bg-gray-950">
        {{ $slot }}
    </div>
</div>
