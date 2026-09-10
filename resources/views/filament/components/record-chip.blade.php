@props(['chip'])
<span class="inline-flex min-w-0 items-center gap-2 align-middle">@if (filled($chip->avatarUrl))<x-filament::avatar
        :src="$chip->avatarUrl"
        :circular="$chip->circular"
        :size="$chip->size"
        alt=""
        class="shrink-0 ring-1 ring-gray-950/5 dark:ring-white/10"
    />@endif<span class="truncate">{{ $chip->name }}</span></span>
